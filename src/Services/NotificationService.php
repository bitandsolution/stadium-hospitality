<?php
/*********************************************************
*                                                        *
*   FILE: src/Services/NotificationService.php           *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*   This is proprietary software                         *
*   developed by bitAND solution for bitAND solution     *
*                                                        *
*********************************************************/

namespace Hospitality\Services;

use Hospitality\Repositories\UserRepository;
use Hospitality\Utils\Logger;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class NotificationService {
    
    /**
     * Invia email di notifica modifica ospite ad admin
     */
    public static function notifyGuestEdit(
        int $guestId,
        string $guestFullName,
        array $changes,
        int $hostessId,
        string $hostessName,
        int $stadiumId
    ): bool {
        try {
            // Get stadium admin email
            $userRepo = new UserRepository();
            $admins = $userRepo->findByStadium($stadiumId, 'stadium_admin');
            
            if (empty($admins)) {
                Logger::warning('No stadium admin found for notification', [
                    'stadium_id' => $stadiumId,
                    'guest_id' => $guestId
                ]);
                return false;
            }

            // Build changes HTML table
            $changesHtml = self::buildChangesTable($changes);

            // Email subject
            $subject = "Modifica Ospite da Hostess - {$guestFullName}";

            // Email body
            $body = self::buildEmailBody(
                $guestFullName,
                $hostessName,
                $changesHtml,
                $guestId
            );

            $sentCount = 0;
            $failedEmails = [];
            
            foreach ($admins as $admin) {
                if (!empty($admin['email'])) {
                    $sent = false;
                    $attempts = 0;
                    $maxAttempts = 2; // Retry once
                    
                    while (!$sent && $attempts < $maxAttempts) {
                        $attempts++;
                        $sent = self::sendEmail($admin['email'], $subject, $body);
                        
                        if (!$sent && $attempts < $maxAttempts) {
                            // Wait 2 seconds before retry
                            sleep(2);
                            Logger::warning('Email send failed, retrying...', [
                                'attempt' => $attempts,
                                'admin_email' => $admin['email']
                            ]);
                        }
                    }
                    
                    if ($sent) {
                        $sentCount++;
                    } else {
                        $failedEmails[] = $admin['email'];
                    }
                }
            }

            if ($sentCount > 0) {
                Logger::info('Guest edit notification sent', [
                    'guest_id' => $guestId,
                    'hostess_id' => $hostessId,
                    'stadium_id' => $stadiumId,
                    'admins_notified' => $sentCount,
                    'failed_count' => count($failedEmails)
                ]);
            }
            
            if (!empty($failedEmails)) {
                Logger::error('Some notification emails failed', [
                    'guest_id' => $guestId,
                    'failed_emails' => $failedEmails
                ]);
            }

            return $sentCount > 0;

        } catch (\Exception $e) {
            Logger::error('Failed to send guest edit notification', [
                'error' => $e->getMessage(),
                'guest_id' => $guestId,
                'hostess_id' => $hostessId
            ]);
            return false;
        }
    }

    /**
     * Build changes comparison table
     */
    private static function buildChangesTable(array $changes): string {
        if (empty($changes)) {
            return '<p>Nessuna modifica registrata.</p>';
        }

        $html = '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%; font-family: Arial, sans-serif;">';
        $html .= '<thead style="background-color: #f5f5f5;">';
        $html .= '<tr>';
        $html .= '<th style="text-align: left;">Campo</th>';
        $html .= '<th style="text-align: left;">Valore Precedente</th>';
        $html .= '<th style="text-align: left;">Nuovo Valore</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        // Field labels italiani
        $fieldLabels = [
            'first_name' => 'Nome',
            'last_name' => 'Cognome',
            'company_name' => 'Azienda',
            'contact_email' => 'Email',
            'contact_phone' => 'Telefono',
            'vip_level' => 'Livello VIP',
            'table_number' => 'Tavolo',
            'seat_number' => 'Posto',
            'room_id' => 'Sala',
            'notes' => 'Note'
        ];

        foreach ($changes as $field => $change) {
            $label = $fieldLabels[$field] ?? ucfirst(str_replace('_', ' ', $field));

            $oldValue = htmlspecialchars(
                $change['old'] ?? '—', 
                ENT_QUOTES | ENT_HTML5, 
                'UTF-8'
            );
            $newValue = htmlspecialchars(
                $change['new'] ?? '—', 
                ENT_QUOTES | ENT_HTML5, 
                'UTF-8'
            );

            $html .= '<tr>';
            $html .= '<td><strong>' . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</strong></td>';
            $html .= '<td style="color: #999;">' . $oldValue . '</td>';
            $html .= '<td style="color: #2563eb; font-weight: bold;">' . $newValue . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';

        return $html;
    }

    /**
     * Build email HTML body
     */
    private static function buildEmailBody(
        string $guestName,
        string $hostessName,
        string $changesHtml,
        int $guestId
    ): string {
        $appUrl = $_ENV['APP_URL'] ?? 'https://checkindigitale.cloud';
        $timestamp = date('d/m/Y H:i:s');
        
        // ✅ Escape HTML con UTF-8
        $guestNameEscaped = htmlspecialchars($guestName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $hostessNameEscaped = htmlspecialchars($hostessName, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return <<<HTML
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body { 
                font-family: Arial, sans-serif; 
                line-height: 1.6; 
                color: #333;
                margin: 0;
                padding: 0;
            }
            .container { 
                max-width: 600px; 
                margin: 0 auto; 
                padding: 20px; 
            }
            .header { 
                background-color: #2563eb; 
                color: white; 
                padding: 20px; 
                text-align: center;
                border-radius: 8px 8px 0 0;
            }
            .content { 
                background-color: #f9fafb; 
                padding: 20px; 
                margin: 0;
                border-left: 1px solid #e5e7eb;
                border-right: 1px solid #e5e7eb;
            }
            .footer { 
                text-align: center; 
                color: #666; 
                font-size: 12px; 
                padding: 20px;
                background-color: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 0 0 8px 8px;
            }
            .alert { 
                background-color: #fef3c7; 
                border-left: 4px solid #f59e0b; 
                padding: 12px; 
                margin: 16px 0;
                border-radius: 4px;
            }
            .button {
                background-color: #2563eb;
                color: white;
                padding: 12px 24px;
                text-decoration: none;
                border-radius: 6px;
                display: inline-block;
                margin-top: 16px;
                font-weight: 500;
            }
            .button:hover {
                background-color: #1d4ed8;
            }
            
            /* ✅ Responsive */
            @media only screen and (max-width: 600px) {
                .container { 
                    padding: 10px !important; 
                }
                table { 
                    font-size: 14px !important; 
                }
                .header h2 {
                    font-size: 18px !important;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2 style="margin: 0;">🔔 Modifica Dati Ospite</h2>
            </div>
            
            <div class="content">
                <div class="alert">
                    <strong>⚠️ Attenzione:</strong> Una hostess ha modificato i dati di un ospite.
                </div>
                
                <h3>Dettagli Operazione:</h3>
                <ul style="list-style: none; padding: 0;">
                    <li style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;"><strong>Ospite:</strong> {$guestNameEscaped}</li>
                    <li style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;"><strong>ID Ospite:</strong> #{$guestId}</li>
                    <li style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;"><strong>Modificato da:</strong> {$hostessNameEscaped}</li>
                    <li style="padding: 8px 0;"><strong>Data/Ora:</strong> {$timestamp}</li>
                </ul>
                
                <h3 style="margin-top: 24px;">Modifiche Effettuate:</h3>
                {$changesHtml}
                
                <div style="text-align: center; margin-top: 24px;">
                    <a href="{$appUrl}/admin-dashboard.html" 
                    class="button">
                        Visualizza Dashboard
                    </a>
                </div>
            </div>
            
            <div class="footer">
                <p style="margin: 8px 0;">Questa è una notifica automatica del sistema Hospitality Manager.</p>
                <p style="margin: 8px 0; color: #999;">Non rispondere a questa email.</p>
            </div>
        </div>
    </body>
    </html>
    HTML;
    }

    /**
     * Send email using PHPMailer
     */
    private static function sendEmail(string $to, string $subject, string $body): bool {
        try {
            $mail = new PHPMailer(true);

            // SMTP Configuration
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_HOST'] ?? 'smtps.aruba.it';
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['MAIL_USERNAME'] ?? '';
            $mail->Password = $_ENV['MAIL_PASSWORD'] ?? '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int)($_ENV['MAIL_PORT'] ?? 587);
            $mail->CharSet = 'UTF-8';

            // Recipients
            $mail->setFrom(
                $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@checkindigitale.cloud',
                $_ENV['MAIL_FROM_NAME'] ?? 'Hospitality Manager'
            );
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));

            $mail->send();
            
            Logger::debug('Email sent successfully', ['to' => $to, 'subject' => $subject]);
            return true;

        } catch (PHPMailerException $e) {
            Logger::error('Failed to send email', [
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send test email (for debugging)
     */
    public static function sendTestEmail(string $to): bool {
        $subject = "Test Email - Hospitality Manager";
        $body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Test Email</h2>
    <p>Questa è una email di test dal sistema Hospitality Manager.</p>
    <p>Se ricevi questa email, la configurazione SMTP è corretta.</p>
    <p>Timestamp: {date('Y-m-d H:i:s')}</p>
</body>
</html>
HTML;

        return self::sendEmail($to, $subject, $body);
    }
}