
<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Eco-Bin Password Recovery
 *
 * Uses existing LavaLust helpers:
 * eco_boot()
 * eco_db_one()
 * eco_db_insert()
 * eco_db_exec()
 *
 * Database: ecobin
 *
 * Tables:
 * users
 * password_reset_tokens
 * refresh_tokens
 *
 * Does not modify existing Login or Registration logic.
 */
class PasswordRecovery extends Controller
{
    private const GENERIC_MESSAGE =
        'If an account exists for that email, password reset instructions will be sent.';

    private const RESET_EXPIRY_MINUTES = 20;

    /**
     * Load existing Eco-Bin services.
     */
    public function before_action()
    {
        eco_boot();
    }

    /**
     * POST /api/auth/forgot-password
     *
     * Request a password reset email.
     */
    public function request()
    {
        $this->api->require_method('POST');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Prevent excessive requests from one IP.
        $this->api->rate_limit(
            'forgot-password-ip:' . $ip,
            10,
            3600
        );

        $input = $this->api->body();

        $email = strtolower(
            trim((string) ($input['email'] ?? ''))
        );

        // Validate email address.
        if (
            strlen($email) > 254 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $this->api->respond_error(
                'Enter a valid email address.',
                422
            );
            return;
        }

        // Prevent excessive requests for one email.
        $this->api->rate_limit(
            'forgot-password-email:' . hash('sha256', $email),
            3,
            3600
        );

        // Check email configuration.
        if (!$this->emailConfigured()) {
            error_log(
                'Eco-Bin recovery: Email configuration unavailable.'
            );

            $this->api->respond_error(
                'Password recovery is temporarily unavailable.',
                503
            );
            return;
        }

        try {
            // Find active user using existing database schema.
            $user = eco_db_one(
                'SELECT id, email
                 FROM users
                 WHERE email = ?
                   AND is_active = 1
                 LIMIT 1',
                [$email]
            );

            // Do not reveal whether the account exists.
            if (!$user) {
                $this->respondGeneric();
                return;
            }

            $userId = (int) $user['id'];

            // Generate a cryptographically secure token.
            $token = bin2hex(random_bytes(32));

            // Store only the hash in the database.
            $tokenHash = hash('sha256', $token);

            $transactionStarted = false;

            try {
                eco_db_exec('START TRANSACTION');
                $transactionStarted = true;

                // Lock the user while preparing a reset request.
                $lockedUser = eco_db_one(
                    'SELECT id
                     FROM users
                     WHERE id = ?
                       AND is_active = 1
                     FOR UPDATE',
                    [$userId]
                );

                if (!$lockedUser) {
                    eco_db_exec('ROLLBACK');
                    $transactionStarted = false;

                    $this->respondGeneric();
                    return;
                }

                // Invalidate previous password reset links.
                eco_db_exec(
                    'DELETE FROM password_reset_tokens
                     WHERE user_id = ?',
                    [$userId]
                );

                // Save the new token hash with 20-minute expiry.
                $insertedId = eco_db_insert(
                    'INSERT INTO password_reset_tokens
                        (
                            user_id,
                            token_hash,
                            expires_at,
                            created_at
                        )
                     VALUES
                        (
                            ?,
                            ?,
                            DATE_ADD(
                                UTC_TIMESTAMP(),
                                INTERVAL 20 MINUTE
                            ),
                            UTC_TIMESTAMP()
                        )',
                    [$userId, $tokenHash]
                );

                if ($insertedId <= 0) {
                    throw new RuntimeException(
                        'Reset token insertion failed.'
                    );
                }

                eco_db_exec('COMMIT');
                $transactionStarted = false;

            } catch (Throwable $exception) {
                if ($transactionStarted) {
                    try {
                        eco_db_exec('ROLLBACK');
                    } catch (Throwable $rollbackError) {
                        error_log(
                            'Eco-Bin recovery: Transaction rollback failed.'
                        );
                    }
                }

                throw $exception;
            }

            // Generate the reset URL from trusted configuration.
            $baseUrl = rtrim(
                (string) getenv('RESET_BASE_URL'),
                '/'
            );

            $resetLink = $baseUrl
                . '/reset-password?token='
                . rawurlencode($token);

            // Send using Brevo transactional email.
            $emailSent = $this->sendResetEmail(
                $email,
                $resetLink
            );

            if (!$emailSent) {
                // Remove the token only if email sending failed.
                try {
                    eco_db_exec(
                        'DELETE FROM password_reset_tokens
                         WHERE token_hash = ?',
                        [$tokenHash]
                    );
                } catch (Throwable $cleanupError) {
                    error_log(
                        'Eco-Bin recovery: Failed-token cleanup unsuccessful.'
                    );
                }

                error_log(
                    'Eco-Bin: Password reset email request failed.'
                );
            }

        } catch (Throwable $exception) {
            // Never log passwords, reset tokens or email addresses.
            error_log(
                'Eco-Bin recovery request failed. Exception: '
                . get_class($exception)
            );
        }

        // Always return a generic message for valid requests.
        $this->respondGeneric();
    }

    /**
     * POST /api/auth/reset-password
     *
     * Validate and consume a reset token.
     */
    public function reset()
    {
        $this->api->require_method('POST');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $this->api->rate_limit(
            'reset-password-ip:' . $ip,
            10,
            3600
        );

        $input = $this->api->body();

        $token = (string) ($input['token'] ?? '');
        $password = (string) ($input['password'] ?? '');

        // Validate token format.
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            $this->api->respond_error(
                'Invalid or expired password reset link.',
                400
            );
            return;
        }

        // Bcrypt supports a maximum of 72 input bytes.
        $passwordBytes = strlen($password);

        if (
            $passwordBytes < 12 ||
            $passwordBytes > 72
        ) {
            $this->api->respond_error(
                'Password must be between 12 and 72 bytes.',
                422
            );
            return;
        }

        $tokenHash = hash('sha256', $token);
        $transactionStarted = false;

        try {
            eco_db_exec('START TRANSACTION');
            $transactionStarted = true;

            // Lock token row to prevent concurrent reuse.
            $resetRecord = eco_db_one(
                'SELECT id, user_id
                 FROM password_reset_tokens
                 WHERE token_hash = ?
                   AND used_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()
                 FOR UPDATE',
                [$tokenHash]
            );

            if (!$resetRecord) {
                eco_db_exec('ROLLBACK');
                $transactionStarted = false;

                $this->api->respond_error(
                    'Invalid or expired password reset link.',
                    400
                );
                return;
            }

            $userId = (int) $resetRecord['user_id'];
            $resetId = (int) $resetRecord['id'];

            // Verify that the account is still active.
            $user = eco_db_one(
                'SELECT id
                 FROM users
                 WHERE id = ?
                   AND is_active = 1
                 FOR UPDATE',
                [$userId]
            );

            if (!$user) {
                throw new RuntimeException(
                    'Reset account unavailable.'
                );
            }

            // Hash the new password using existing bcrypt scheme.
            $newHash = password_hash(
                $password,
                PASSWORD_BCRYPT
            );

            if (!$newHash) {
                throw new RuntimeException(
                    'Password hashing failed.'
                );
            }

            // Consume the token exactly once.
            $consumed = eco_db_exec(
                'UPDATE password_reset_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE id = ?
                   AND used_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()',
                [$resetId]
            );

            if ($consumed !== 1) {
                throw new RuntimeException(
                    'Reset token already consumed or expired.'
                );
            }

            // Update the user's password.
            $updated = eco_db_exec(
                'UPDATE users
                 SET password_hash = ?
                 WHERE id = ?
                   AND is_active = 1',
                [$newHash, $userId]
            );

            if ($updated !== 1) {
                throw new RuntimeException(
                    'Password update failed.'
                );
            }

            // Revoke refresh tokens belonging to this user.
            eco_db_exec(
                'DELETE FROM refresh_tokens
                 WHERE user_id = ?',
                [$userId]
            );

            // Delete any other reset requests for this user.
            eco_db_exec(
                'DELETE FROM password_reset_tokens
                 WHERE user_id = ?
                   AND id <> ?',
                [$userId, $resetId]
            );

            eco_db_exec('COMMIT');
            $transactionStarted = false;

            $this->api->respond([
                'message' =>
                    'Your password has been reset. You can now sign in.'
            ]);

            return;

        } catch (Throwable $exception) {
            if ($transactionStarted) {
                try {
                    eco_db_exec('ROLLBACK');
                } catch (Throwable $rollbackError) {
                    error_log(
                        'Eco-Bin reset: Transaction rollback failed.'
                    );
                }
            }

            error_log(
                'Eco-Bin password reset failed. Exception: '
                . get_class($exception)
            );

            $this->api->respond_error(
                'Unable to reset password. Please request a new link.',
                400
            );

            return;
        }
    }

    /**
     * Generic response prevents account enumeration.
     */
    private function respondGeneric(): void
    {
        $this->api->respond([
            'message' => self::GENERIC_MESSAGE
        ]);
    }

    /**
     * Verify backend email configuration.
     */
    private function emailConfigured(): bool
    {
        $key = getenv('BREVO_API_KEY');
        $from = getenv('MAIL_FROM_EMAIL');
        $baseUrl = getenv('RESET_BASE_URL');

        if (!$key || !$from || !$baseUrl) {
            return false;
        }

        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $url = parse_url($baseUrl);

        if (
            !$url ||
            !isset($url['scheme'], $url['host'])
        ) {
            return false;
        }

        // Reject credentials and unexpected query components.
        if (
            isset($url['user']) ||
            isset($url['pass']) ||
            isset($url['query']) ||
            isset($url['fragment'])
        ) {
            return false;
        }

        $scheme = strtolower($url['scheme']);
        $host = strtolower($url['host']);

        // Production requires HTTPS.
        if ($scheme === 'https') {
            return true;
        }

        // Allow HTTP only for local development.
        return $scheme === 'http'
            && in_array(
                $host,
                ['localhost', '127.0.0.1'],
                true
            );
    }

    /**
     * Send password reset email through Brevo HTTPS API.
     *
     * Returns true only when Brevo accepts the request.
     *
     * Diagnostics:
     * - HTTP status code
     * - cURL error number
     *
     * No secrets or response bodies are logged.
     */
    private function sendResetEmail(
        string $recipient,
        string $resetLink
    ): bool {
        if (!function_exists('curl_init')) {
            error_log(
                'Eco-Bin Brevo delivery failed: cURL unavailable.'
            );
            return false;
        }

        $safeLink = htmlspecialchars(
            $resetLink,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $html = '
            <div style="
                font-family:Arial,sans-serif;
                line-height:1.6;
                color:#164b36;
                max-width:560px;
                margin:auto;
            ">
                <h2>Eco-Bin Password Reset</h2>

                <p>
                    We received a request to reset
                    your Eco-Bin account password.
                </p>

                <p>
                    <a href="' . $safeLink . '"
                       style="
                           display:inline-block;
                           background:#206d4b;
                           color:#ffffff;
                           padding:12px 20px;
                           border-radius:8px;
                           text-decoration:none;
                           font-weight:bold;
                       ">
                        Reset Password
                    </a>
                </p>

                <p>
                    This link expires in 20 minutes
                    and can only be used once.
                </p>

                <p>
                    If you did not request this change,
                    you may safely ignore this email.
                </p>

                <p>
                    EcoBin System
                </p>
            </div>
        ';

        $payload = json_encode([
            'sender' => [
                'name' => 'EcoBin System',
                'email' => getenv('MAIL_FROM_EMAIL')
            ],
            'to' => [
                [
                    'email' => $recipient
                ]
            ],
            'subject' => 'Reset your Eco-Bin password',
            'htmlContent' => $html
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init(
            'https://api.brevo.com/v3/smtp/email'
        );

        if ($ch === false) {
            error_log(
                'Eco-Bin Brevo delivery failed: cURL initialization failed.'
            );
            return false;
        }

        $optionsSet = curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . getenv('BREVO_API_KEY')
            ]
        ]);

        if (!$optionsSet) {
            curl_close($ch);

            error_log(
                'Eco-Bin Brevo delivery failed: cURL configuration failed.'
            );

            return false;
        }

        // Execute the HTTPS request.
        $result = curl_exec($ch);

        // Capture diagnostics before closing cURL.
        $status = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        $curlErrorNumber = curl_errno($ch);

        curl_close($ch);

        // Brevo API returns HTTP 201 when accepted.
        if (
            $result === false ||
            $status !== 201
        ) {
            error_log(
                'Eco-Bin Brevo delivery failed. '
                . 'HTTP status: ' . $status
                . '; cURL error code: ' . $curlErrorNumber
            );

            return false;
        }

        // Request accepted by Brevo.
        return true;
    }
}
