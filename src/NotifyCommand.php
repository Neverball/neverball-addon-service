<?php

class NotifyCommand
{
    public static function run(?string $targetToken = null): void
    {
        $tokenDir = STORAGE_DIR . '/tokens';
        if (!is_dir($tokenDir)) {
            echo "No tokens directory found at $tokenDir\n";
            exit(1);
        }

        $files = glob($tokenDir . '/*');
        if (empty($files)) {
            echo "No token files found in $tokenDir\n";
            exit(0);
        }

        // Filter and clean token filenames (stripping .used if present)
        $tokens = [];
        foreach ($files as $file) {
            $base  = basename($file);
            $clean = preg_replace('/\.used$/', '', $base);
            if (preg_match('/^[0-9a-f]{32}$/', $clean)) {
                $tokens[$clean] = $file;
            }
        }

        if (empty($tokens)) {
            echo "No valid token files found in $tokenDir\n";
            exit(0);
        }

        $tool = new AddonTool();

        if ($targetToken) {
            $cleanTarget = preg_replace('/\.used$/', '', trim($targetToken));
            if (!isset($tokens[$cleanTarget])) {
                echo "Error: Token '$cleanTarget' not found in $tokenDir\n";
                exit(1);
            }

            echo "Resending notification for token: $cleanTarget...\n";
            $success = $tool->resendNotificationForToken($cleanTarget);
            if ($success) {
                echo "SUCCESS: Notification resent successfully!\n";
            } else {
                echo "ERROR: Failed to resend notification. Check logs for details.\n";
            }
            return;
        }

        echo "=======================================================\n";
        echo " Neverball Addon Service - Notification Resend Tool\n";
        echo "=======================================================\n\n";

        $index = 1;
        $keyList = array_keys($tokens);

        foreach ($keyList as $t) {
            $data  = AddonTool::peekToken(STORAGE_DIR, $t);
            $name  = $data['addonName'] ?? 'Addon';
            $id    = $data['id'] ?? 'unknown';
            $sub   = $data['submitter_name'] ?? 'N/A';
            $date  = $data['submitted_at'] ?? 'N/A';

            echo "[$index] Addon: $name ($id)\n";
            echo "    Submitter: $sub\n";
            echo "    Token:     $t\n";
            echo "    Submitted: $date\n";
            echo "-------------------------------------------------------\n\n";
            $index++;
        }

        echo "Usage to resend a specific notification:\n";
        echo "  php notify <TOKEN>\n\n";
        echo "Example:\n";
        echo "  php notify " . $keyList[0] . "\n";
    }
}
