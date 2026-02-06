<?php
date_default_timezone_set('Europe/Warsaw');

// Log file path - using /tmp for write permissions
$logFile = "/tmp/ifttt.log";

// Read full email from stdin
$mail = file_get_contents("php://stdin");
if ($mail === false || trim($mail) === '') {
    exit(0);
}

// Extract commonly used fields
$from = 'UNKNOWN';
if (preg_match('/^From:\s*(.+)$/im', $mail, $m)) {
    $from = trim($m[1]);
}

$subject = 'UNKNOWN';
if (preg_match('/^Subject:\s*(.+)$/im', $mail, $m)) {
    $subject = trim($m[1]);
}

$returnPath = 'UNKNOWN';
if (preg_match('/^Return-Path:\s*(.+)$/im', $mail, $m)) {
    $returnPath = trim($m[1]);
}

// Extract date from mail header if present
$date = date('Y-m-d H:i:s');
if (preg_match('/^Date:\s*(.+)$/im', $mail, $m)) {
    $ts = strtotime($m[1]);
    if ($ts !== false) {
        $date = date('Y-m-d H:i:s', $ts);
    }
}

// Check if this is an IFTTT email
$isIFTTT = (
    preg_match('/ifttt\.com/i', $from) ||
    preg_match('/ifttt\.com/i', $returnPath) ||
    preg_match('/@emails\.ifttt\.com/i', $returnPath)
);

if ($isIFTTT) {
    // Log IFTTT emails to dedicated log
    $logLine = sprintf(
        "[%s] %s\n",
        $date,
        $subject
    );
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

exit(0);
