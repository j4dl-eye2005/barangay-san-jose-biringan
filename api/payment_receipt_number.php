<?php
/**
 * Creates the portal's stable receipt identifier from the immutable payment ID.
 * This is a portal reference, not a cashier- or provider-issued official receipt.
 */
function portalPaymentReceiptNumber(int $paymentId, ?string $recordedAtUtc = null): string
{
    $date = $recordedAtUtc
        ? (new DateTimeImmutable($recordedAtUtc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))
        : new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));

    return 'SJ-RCPT-' . $date->format('Y') . '-' . str_pad((string) $paymentId, 8, '0', STR_PAD_LEFT);
}
