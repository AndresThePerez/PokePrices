<?php

declare(strict_types=1);

/*
 * PokéPrices local webhook sink (plan P5-i1 D12): a signature-verifying receiver for the delivery evidence.
 * Standalone on purpose: it loads nothing from the application, so its verification is independent of the sender.
 *
 * Serve:   SINK_SECRET_FILE=<file> SINK_LOG=<log.jsonl> [SINK_FAIL_FIRST=<n>] php -S <host>:<port> sink.php
 * Summary: php sink.php --summary <log.jsonl>
 *
 * Each POST is verified as Standard Webhooks: webhook-id, webhook-timestamp and webhook-signature present, the
 * timestamp within 300 s of this clock, and one of the space-separated v1 signatures equal (constant time) to
 * base64(HMAC-SHA256(key, "<id>.<timestamp>.<body>")), key = the base64 part of the whsec_ secret in
 * SINK_SECRET_FILE. A failed check answers 401. A verified request answers 503 for the first SINK_FAIL_FIRST
 * verified attempts of each webhook-id (failure injection), then 204; a repeat after a 204 is a duplicate the
 * receiver drops (still 204). One JSON line per request goes to SINK_LOG: id, attempt, verified, answered,
 * duplicate_after_success. The secret, the signatures and the body are never written anywhere.
 */

const SINK_TOLERANCE_SECONDS = 300;

/** @return list<array<string, mixed>> */
function sink_read_log(string $path): array
{
    if (! is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $out = [];
    foreach ($lines === false ? [] : $lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/** @return array<string, int> */
function sink_summary(string $path): array
{
    $rows = sink_read_log($path);
    $unique = [];
    $delivered = [];
    $verified = 0;
    $invalid = 0;
    $duplicates = 0;
    $maxAttempts = 0;
    foreach ($rows as $row) {
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        if (($row['verified'] ?? false) === true) {
            $verified++;
            $unique[$id] = true;
            $maxAttempts = max($maxAttempts, (int) ($row['attempt'] ?? 0));
            if (($row['answered'] ?? 0) === 204) {
                $delivered[$id] = true;
            }
        } else {
            $invalid++;
        }
        if (($row['duplicate_after_success'] ?? false) === true) {
            $duplicates++;
        }
    }

    return [
        'requests' => count($rows),
        'verified' => $verified,
        'unique_ids' => count($unique),
        'delivered_ids' => count($delivered),
        'invalid' => $invalid,
        'duplicates_after_success' => $duplicates,
        'max_attempts' => $maxAttempts,
    ];
}

function sink_header(string $name): string
{
    $value = $_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))] ?? '';

    return is_string($value) ? trim($value) : '';
}

function sink_verified(string $id, string $timestamp, string $signatures, string $body, string $secret): bool
{
    if ($id === '' || $timestamp === '' || $signatures === '' || preg_match('/^[0-9]{1,12}$/D', $timestamp) !== 1) {
        return false;
    }
    if (abs(time() - (int) $timestamp) > SINK_TOLERANCE_SECONDS) {
        return false;
    }
    if (! str_starts_with($secret, 'whsec_')) {
        return false;
    }
    $key = base64_decode(substr($secret, 6), true);
    if ($key === false || $key === '') {
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));
    foreach (preg_split('/\s+/', $signatures) ?: [] as $candidate) {
        if (str_starts_with($candidate, 'v1,') && hash_equals($expected, substr($candidate, 3))) {
            return true;
        }
    }

    return false;
}

function sink_serve(): void
{
    $secretFile = (string) getenv('SINK_SECRET_FILE');
    $logPath = (string) getenv('SINK_LOG');
    $failFirst = max(0, (int) getenv('SINK_FAIL_FIRST'));
    if ($logPath === '' || $secretFile === '' || ! is_readable($secretFile)) {
        http_response_code(500);

        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);

        return;
    }

    $secret = trim((string) file_get_contents($secretFile));
    $body = (string) file_get_contents('php://input');
    $id = sink_header('webhook-id');
    $verified = sink_verified($id, sink_header('webhook-timestamp'), sink_header('webhook-signature'), $body, $secret);

    $log = fopen($logPath, 'c+');
    if ($log === false) {
        http_response_code(500);

        return;
    }
    flock($log, LOCK_EX);
    $attempt = 1;
    $verifiedBefore = 0;
    $succeeded = false;
    foreach (sink_read_log($logPath) as $row) {
        if (($row['id'] ?? null) !== $id) {
            continue;
        }
        $attempt++;
        if (($row['verified'] ?? false) === true) {
            $verifiedBefore++;
            $succeeded = $succeeded || ($row['answered'] ?? 0) === 204;
        }
    }
    $answered = ! $verified ? 401 : ($succeeded || $verifiedBefore >= $failFirst ? 204 : 503);
    $line = json_encode([
        'id' => $id,
        'attempt' => $attempt,
        'verified' => $verified,
        'answered' => $answered,
        'duplicate_after_success' => $verified && $succeeded,
    ], JSON_UNESCAPED_SLASHES);
    fseek($log, 0, SEEK_END);
    fwrite($log, $line."\n");
    fflush($log);
    flock($log, LOCK_UN);
    fclose($log);

    http_response_code($answered);
}

if (PHP_SAPI === 'cli') {
    $args = $argv ?? [];
    if (($args[1] ?? '') !== '--summary' || ! isset($args[2])) {
        fwrite(STDERR, "usage: php sink.php --summary <log.jsonl>\n");
        exit(2);
    }
    echo json_encode(sink_summary($args[2]), JSON_UNESCAPED_SLASHES), "\n";
} else {
    sink_serve();
}
