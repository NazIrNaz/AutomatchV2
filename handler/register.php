<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Include database connection
include '../db/connection.php';

// Include PHPMailer library
require '../vendor/autoload.php'; // Ensure the autoload file is included

// Initialize response array
$response = [
    'success' => false,
    'message' => ''
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $role = 'user';

    // Validate inputs
    if (empty($username) || empty($email) || empty($password)) {
        $response['message'] = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = "Invalid email format.";
    } elseif (strlen($password) < 6) {
        $response['message'] = "Password must be at least 6 characters long.";
    } else {
        // Check if the email or username already exists
        $checkQuery = "SELECT * FROM users WHERE email = ? OR username = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("ss", $email, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $response['message'] = "Username or Email already exists.";
        } else {
            // Hash the password
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // Insert new user
            $insertQuery = "INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param("ssss", $username, $hashedPassword, $email, $role);

            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Registration successful. A welcome email has been sent.";

                // Send Welcome Email
                sendWelcomeEmail($email, $username);
            } else {
                $response['message'] = "Error: " . $stmt->error;
            }
        }

        $stmt->close();
    }

    $conn->close();
}

// Function to send a welcome email using PHPMailer and SMTP
function sendWelcomeEmail($recipientEmail, $username)
{
    $smtpUser = 'setyourownemailhere@gmail.com';
    $smtpPass = 'set the App Password here'; // keep in env/config, not in source

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Debug while testing (disable in prod)
        // $mail->SMTPDebug  = 2;
        // $mail->Debugoutput = function($str, $level) { error_log("PHPMailer [$level]: $str"); };

        // SMTP
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // If Laragon/Windows has CA issues, fix php.ini CA path, or as a temp dev workaround:
        // $mail->SMTPOptions = ['ssl' => ['verify_peer' => false,'verify_peer_name' => false,'allow_self_signed' => true]];

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($smtpUser, 'Automatch Team');
        $mail->addAddress($recipientEmail, $username);
        $mail->addReplyTo('support@automatch.local', 'Automatch Support');

        $mail->Subject = "Welcome to Automatch, $username!";
        $mail->isHTML(true);

        $safeName = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

        // ===== Inline-styled email (no Tailwind required on client) =====
        $mail->Body = '
<!doctype html>
<html lang="en">
  <body style="margin:0; padding:0; background:#f6f7fb; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f6f7fb;">
      <tr>
        <td align="center" style="padding:24px;">
          <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px; background:#ffffff; border-radius:16px; overflow:hidden;">
            <!-- Header -->
            <tr>
              <td style="background:#111827; color:#ffffff; padding:24px;">
                <h1 style="margin:0; font-size:22px; line-height:1.3;">Welcome to Automatch 👋</h1>
                <p style="margin:8px 0 0; font-size:14px; opacity:.9;">Hi '.$safeName.', we\'re glad you\'re here.</p>
              </td>
            </tr>

            <!-- Body -->
            <tr>
              <td style="padding:24px; color:#111827;">
                <p style="margin:0 0 12px;">Thanks for joining Automatch! From budget planning to shortlisting cars that fit your needs, we\'ll help you make a confident choice.</p>

                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0;">
                  <tr>
                    <td style="background:#2563eb; border-radius:10px;">
                      <a href="http://localhost/AutomatchV2/index.php"
                         style="display:inline-block; padding:12px 18px; color:#ffffff; text-decoration:none; font-weight:bold; font-size:14px;">
                        Get Started →
                      </a>
                    </td>
                  </tr>
                </table>

                <h2 style="font-size:16px; margin:20px 0 8px;">What\'s next?</h2>
                <ul style="padding-left:18px; margin:8px 0 0;">
                  <li style="margin:6px 0;">Create your profile and set your budget</li>
                  <li style="margin:6px 0;">Pick your preferred brands and car type</li>
                  <li style="margin:6px 0;">Generate recommendations with a click</li>
                </ul>

                <div style="margin:20px 0 0; border:1px solid #e5e7eb; border-radius:12px; padding:14px;">
                  <h3 style="font-size:14px; margin:0 0 6px;">Privacy at a glance</h3>
                  <p style="margin:0; font-size:13px; color:#374151;">
                    We only collect what\'s needed to power your recommendations. You can update or delete your data anytime.
                    <a href="http://localhost/AutomatchV2/privacy" style="color:#2563eb; text-decoration:none;">Learn more</a>.
                  </p>
                </div>

                <p style="margin:18px 0 0; font-size:13px; color:#6b7280;">
                  Tip: Add this email to your contacts so our messages don\'t land in spam.
                </p>
              </td>
            </tr>

            <!-- Footer -->
            <tr>
              <td style="background:#f9fafb; color:#6b7280; padding:18px; text-align:center; font-size:12px;">
                Automatch • Kuala Lumpur, MY<br>
                Need help? <a href="mailto:support@automatch.local" style="color:#2563eb; text-decoration:none;">Contact support</a>
              </td>
            </tr>
          </table>

          <div style="max-width:560px; margin:12px auto 0; color:#9ca3af; font-size:11px; text-align:center;">
            You receive this email because you signed up for Automatch.
          </div>
        </td>
      </tr>
    </table>
  </body>
</html>';

        $mail->AltBody =
"Welcome to Automatch, $username!

Thanks for joining Automatch. Get started: http://localhost/Automatch/index.php

Privacy: We collect only what’s needed for recommendations. Learn more at /privacy.
Need help? Email support@automatch.local";

        $mail->send();
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('PHPMailer exception: '.$e->getMessage().' | '.$mail->ErrorInfo);
    } catch (\Throwable $t) {
        error_log('Mailer Throwable: '.$t->getMessage());
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
exit();
