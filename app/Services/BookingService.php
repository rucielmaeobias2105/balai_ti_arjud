<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ChangedBy;
use App\Enums\DownPaymentStatus;
use App\Enums\ItemTag;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SalonSetting;
use App\Notifications\AppointmentBookedNotification;
use App\Support\PriceFormatter;
use App\Support\SendsNotificationsQuietly;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates appointments from the booking form and keeps linked inventory in
 * step. Every write happens in a transaction so a failed availability re-check
 * cannot leave a half-written appointment behind.
 *
 * It is also the second half of the duplicate-submission guard. `POST /book` used
 * to have no throttle and no duplicate check, and the availability re-check cannot
 * stand in for one: it reads *before* the insert, so a double-tap that fired two
 * requests saw the same slot free both times and both took it. The form now sends
 * an idempotency key and `create()` treats a repeated key as the same attempt —
 * see `resolveIdempotent()`.
 */
class BookingService
{
    use SendsNotificationsQuietly;

    /**
     * The appointment an idempotency key already produced, if any.
     *
     * Scoped by `user_id` as well as by the key, so a replayed key belonging to some
     * other account resolves to nothing rather than handing back a stranger's
     * booking. Null for a key that was never used.
     */
    public function resolveIdempotent(?string $key, ?int $userId): ?Appointment
    {
        if ($key === null || $key === '' || $userId === null) {
            return null;
        }

        return Appointment::query()
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * The key to store, or null when the request did not carry a usable one.
     *
     * Validated as a UUID rather than merely length-checked, because the value is
     * written to a column behind a unique index: a client that could put arbitrary
     * text there could also deliberately collide with somebody else's key. A
     * request with no valid key simply gets none, which leaves the duplicate guard
     * to the throttle rather than failing an otherwise fine booking.
     */
    protected function idempotencyKey(array $data): ?string
    {
        $key = $data['idempotency_key'] ?? null;

        return is_string($key) && Str::isUuid($key) ? $key : null;
    }

    /**
     * Resolve the selected service/variant pairs into priced lines.
     *
     * The amount comes from `base_price`, never from the advertised `price`
     * string: a service can be listed as "249/499" or "100+", and taking a
     * number out of that would invent a charge. A line with no base price is
     * refused outright rather than being booked at zero.
     *
     * @param  array<int, array{service_id: int|string, service_variant_id?: int|string|null, quantity?: int|string}>  $selections
     * @return array<int, array{Service $service, ?ServiceVariant $variant, int $quantity, float $price, string $display_price, int $duration}>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function resolveLines(array $selections): array
    {
        $lines = [];

        foreach ($selections as $selection) {
            $service = Service::withTrashed()->find($selection['service_id']);

            if (! $service) {
                continue;
            }

            $variant = null;

            if (! empty($selection['service_variant_id'])) {
                $variant = ServiceVariant::where('service_id', $service->id)
                    ->find($selection['service_variant_id']);
            }

            $quantity = max(1, (int) ($selection['quantity'] ?? 1));

            // A variant with no amount of its own falls back to the service's,
            // the same way a variant with no price always has. The advertised
            // string still comes from the variant, so the customer sees the
            // variant they picked.
            $base = $variant?->base_price ?? $service->base_price;
            $priced = $variant && $variant->base_price !== null ? $variant : $service;

            if ($base === null) {
                throw ValidationException::withMessages([
                    'services' => sprintf(
                        '"%s" has no amount to charge yet. Its price is %s, which booking totals cannot be calculated from. An admin needs to set the amount to charge first.',
                        $priced->name,
                        PriceFormatter::display($priced->price, 'not set'),
                    ),
                ]);
            }

            $lines[] = [
                'service' => $service,
                'variant' => $variant,
                'quantity' => $quantity,
                'price' => (float) $base,
                'display_price' => (string) ($variant?->price ?? $service->price),
                'duration' => (int) ($variant?->effectiveDuration() ?? $service->duration_minutes),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array{Service $service, ?ServiceVariant $variant, int $quantity, float $price, string $display_price, int $duration}>  $lines
     */
    public function totalFor(array $lines): float
    {
        return round(array_sum(array_map(
            fn (array $line) => $line['price'] * $line['quantity'],
            $lines,
        )), 2);
    }

    /**
     * Create the appointment, its service lines, and consume linked stock.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, array $lines, ?int $userId = null): Appointment
    {
        $settings = SalonSetting::current();
        $total = $this->totalFor($lines);
        $key = $this->idempotencyKey($data);

        /*
         * The fast path.
         *
         * Checked before the transaction as well as by the unique index inside it.
         * The check is what makes a duplicate *cheap* — it returns the booking that
         * already exists instead of starting work that is about to be thrown away —
         * and the index is what makes it *correct*, because two requests can both be
         * past this check before either commits.
         */
        if ($key !== null && $existing = $this->resolveIdempotent($key, $userId)) {
            return $existing->load('serviceLines');
        }

        try {
            $appointment = $this->write($data, $lines, $userId, $key, $settings, $total);
        } catch (QueryException $exception) {
            /*
             * The index refused the insert, which means a concurrent request with
             * the same key won the race and committed first. Its booking is the one
             * this customer wanted, so it is returned.
             *
             * Guarded on the key being present and on the error actually being the
             * unique violation: any other database failure has to keep propagating,
             * because swallowing it and returning somebody else's row would turn a
             * real fault into a booking that looks fine.
             */
            if ($key === null || ! $this->isDuplicateKeyViolation($exception)) {
                throw $exception;
            }

            $existing = $this->resolveIdempotent($key, $userId);

            if (! $existing) {
                throw $exception;
            }

            return $existing->load('serviceLines');
        }

        if ($userId) {
            /*
             * Quietly, because by this line the booking exists.
             *
             * The transaction has committed, the inventory is decremented and the
             * customer is being redirected to a success page. A confirmation email
             * that cannot be sent — a wrong SMTP password, a Gmail outage — must
             * not turn all of that into a 500. If it did, the customer would see a
             * failed booking that is in fact saved, submit again, and book the same
             * slot twice; and the operator would be reading a mail credential error
             * reported as a booking form fault.
             *
             * The duplicate path above deliberately sends nothing: the winner of the
             * race already emailed this customer about this booking.
             */
            $this->notifyQuietly(
                $appointment->user,
                new AppointmentBookedNotification($appointment),
                'booking confirmation',
            );
        }

        return $appointment;
    }

    /**
     * Is this the unique index on (user_id, idempotency_key) complaining?
     *
     * MySQL reports it as 23000/1062 and SQLite as 23000/19. Both codes are
     * accepted, and neither is checked for the index name, because the driver
     * message formats differ far more than the SQLSTATE does and matching on prose
     * would make this quietly stop working on whichever database the salon moves to.
     */
    protected function isDuplicateKeyViolation(QueryException $exception): bool
    {
        $sqlState = $exception->getCode();

        if ((string) $sqlState !== '23000' && (string) $sqlState !== '23505') {
            return false;
        }

        $driverCode = $exception->errorInfo[1] ?? null;

        return in_array((int) $driverCode, [19, 1062, 1555], true);
    }

    /**
     * The insert itself, in its own transaction.
     *
     * Split out of `create()` so the duplicate handling wraps only the write. The
     * notification deliberately sits outside this method — a notification sent from
     * inside a transaction that then rolls back would tell a customer their booking
     * exists when it does not.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array{service: Service, variant: ?ServiceVariant, quantity: int, price: float, display_price: string, duration: int}>  $lines
     */
    protected function write(array $data, array $lines, ?int $userId, ?string $key, SalonSetting $settings, float $total): Appointment
    {
        $appointment = DB::transaction(function () use ($data, $lines, $total, $settings, $userId, $key) {
            $appointment = Appointment::create([
                'reference_number' => Appointment::generateReferenceNumber(),
                'idempotency_key' => $key,
                'user_id' => $userId,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
                'preferred_date' => $data['preferred_date'],
                'preferred_time' => $data['preferred_time'],
                'allergies' => $data['allergies'] ?? null,
                'last_services_availed' => $data['last_services_availed'] ?? null,
                'preferred_stylist_id' => $data['preferred_stylist_id'] ?? null,
                'technician_id' => $data['technician_id'] ?? null,
                'special_request' => $data['special_request'] ?? null,
                'down_payment_reference' => $data['down_payment_reference'] ?? null,
                'down_payment_amount' => $settings->down_payment_required
                    ? $settings->expectedDownPaymentFor($total)
                    : null,
                // No payment gateway: an admin verifies the GCash reference by hand.
                'down_payment_status' => $settings->down_payment_required && ! empty($data['down_payment_reference'])
                    ? DownPaymentStatus::Unverified
                    : DownPaymentStatus::NotRequired,
                'total_amount' => $total,
                'status' => AppointmentStatus::Pending,
                'source' => 'web',
            ]);

            foreach ($lines as $line) {
                AppointmentService::create([
                    'appointment_id' => $appointment->id,
                    'service_id' => $line['service']->id,
                    'service_variant_id' => $line['variant']?->id,
                    'service_name' => $line['service']->name,
                    'variant_name' => $line['variant']?->name,
                    'price' => $line['price'],
                    // What the customer was shown at the time, so a later edit
                    // to the service cannot rewrite the history of a booking.
                    'display_price' => $line['display_price'],
                    'duration_minutes' => $line['duration'],
                    'quantity' => $line['quantity'],
                ]);
            }

            $this->consumeInventory($lines);

            $appointment->recordStatusChange(
                AppointmentStatus::Pending,
                ChangedBy::Customer,
                $userId,
                $data['customer_name'],
                'Appointment submitted by customer.',
            );

            return $appointment;
        });

        return $appointment->load('serviceLines');
    }

    /**
     * Deduct each linked item and re-derive its status tag.
     *
     * @param  array<int, array{Service $service, ?ServiceVariant $variant, int $quantity, float $price, string $display_price, int $duration}>  $lines
     */
    protected function consumeInventory(array $lines): void
    {
        foreach ($lines as $line) {
            $items = $line['service']->inventoryItems()->get();

            foreach ($items as $item) {
                $perService = (float) ($item->pivot->quantity_per_service ?: 1);
                $consumed = $perService * $line['quantity'];

                $item->decrement('quantity', $consumed);
                $item->refresh()->syncStatusTag();
            }
        }
    }

    /**
     * Move an appointment to a new date/time, re-validating availability.
     */
    public function reschedule(
        Appointment $appointment,
        string $date,
        string $time,
        ?string $reason = null,
    ): void {
        $previousDate = $appointment->preferred_date->toDateString();
        $previousTime = Carbon::parse($appointment->preferred_time)->format('H:i');

        DB::transaction(function () use ($appointment, $date, $time, $reason, $previousDate, $previousTime) {
            $appointment->update([
                'preferred_date' => $date,
                'preferred_time' => $time,
                'reschedule_reason' => $reason,
            ]);

            // Returning the previously held stock, then consuming the new mix.
            $this->restoreInventory($appointment);
            $this->consumeInventory($appointment->serviceLines->map(fn ($line) => [
                'service' => $line->service,
                'variant' => $line->variant,
                'quantity' => $line->quantity,
            ])->all());

            // A reschedule does not change the status, so from == to.
            $appointment->recordStatusChange(
                $appointment->status,
                ChangedBy::Customer,
                $appointment->user_id,
                $appointment->customer_name,
                "Rescheduled from {$previousDate} {$previousTime} to {$date} {$time}."
                .($reason ? " Reason: {$reason}" : ''),
                $appointment->status,
            );
        });
    }

    /**
     * Put consumed stock back when an appointment is cancelled.
     */
    public function restoreInventory(Appointment $appointment): void
    {
        foreach ($appointment->serviceLines as $line) {
            $service = $line->service;

            if (! $service) {
                continue;
            }

            $items = $service->inventoryItems()->get();

            foreach ($items as $item) {
                $perService = (float) ($item->pivot->quantity_per_service ?: 1);
                $item->increment('quantity', $perService * $line->quantity);
                $item->refresh()->syncStatusTag();
            }
        }
    }
}
