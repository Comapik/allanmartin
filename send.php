<?php
/**
 * send.php — Traitement du formulaire de contact
 *
 * Protections :
 *   - Honeypot (champ caché que les bots remplissent)
 *   - Vérification temporelle (soumission < 3 s → bot)
 *   - Validation stricte côté serveur
 *   - Rate limiting par session (1 envoi / 5 min)
 *   - Détection de mots-clés spam
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

session_start();

// ══════════════════════════════════════════════════════
//  CONFIGURATION  ← modifier avant mise en production
// ══════════════════════════════════════════════════════
define('RECIPIENT_EMAIL', 'contact@allanmartin.fr');  // adresse de réception
define('SENDER_DOMAIN',   'allanmartin.fr');           // domaine O2switch
define('RATE_LIMIT_SEC',  300);                        // 5 min entre deux envois
define('MIN_DELAY_MS',    3000);                       // délai min. de remplissage (ms)
// ══════════════════════════════════════════════════════

function json_error(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// ── Méthode POST uniquement ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Méthode non autorisée.', 405);
}

// ── Honeypot : le champ "website" doit rester vide ────
if (!empty($_POST['website'])) {
    // Fausse réponse positive pour dérouter le bot
    echo json_encode(['success' => true, 'message' => 'Message envoyé.']);
    exit;
}

// ── Vérification temporelle (> 3 s depuis le chargement) ──
$load_time = intval($_POST['_time'] ?? 0);
if ($load_time === 0 || (time() * 1000 - $load_time) < MIN_DELAY_MS) {
    json_error('Formulaire soumis trop rapidement. Veuillez réessayer.');
}

// ── Rate limiting par session ──────────────────────────
if (
    isset($_SESSION['last_contact_send']) &&
    (time() - $_SESSION['last_contact_send']) < RATE_LIMIT_SEC
) {
    json_error('Merci de patienter quelques minutes avant de renvoyer un message.', 429);
}

// ── Lecture et nettoyage des champs ───────────────────
$name    = trim(strip_tags($_POST['name']    ?? ''));
$email   = trim(          $_POST['email']   ?? '' );
$subject = trim(strip_tags($_POST['subject'] ?? ''));
$message = trim(strip_tags($_POST['message'] ?? ''));

// ── Validation ────────────────────────────────────────
if ($name === '' || mb_strlen($name) > 100) {
    json_error('Nom invalide.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    json_error('Adresse e-mail invalide.');
}
if ($message === '' || mb_strlen($message) > 5000) {
    json_error('Message invalide (1 à 5 000 caractères).');
}

// ── Détection spam par mots-clés ──────────────────────
$spam_patterns = [
    'http://', 'https://', '<a ', '<script',
    'viagra', 'casino', 'buy now', 'click here', 'free offer',
    'cryptocurrency', 'make money',
];
$haystack = strtolower($message . ' ' . $subject);
foreach ($spam_patterns as $pattern) {
    if (str_contains($haystack, $pattern)) {
        json_error('Message refusé.');
    }
}

// ── Construction de l'e-mail ──────────────────────────
$mail_to      = RECIPIENT_EMAIL;
$mail_from    = 'no-reply@' . SENDER_DOMAIN;
$mail_subject = mb_encode_mimeheader(
    '[Contact] ' . ($subject !== '' ? $subject : 'Nouveau message'),
    'UTF-8',
    'B'
);

$mail_body  = "Nouveau message reçu via le formulaire de contact.\n";
$mail_body .= str_repeat('─', 48) . "\n\n";
$mail_body .= "Nom     : {$name}\n";
$mail_body .= "E-mail  : {$email}\n";
$mail_body .= "Objet   : {$subject}\n\n";
$mail_body .= "Message :\n{$message}\n\n";
$mail_body .= str_repeat('─', 48) . "\n";
$mail_body .= "Envoyé depuis allanmartin.fr\n";

$headers  = "From: Allan Martin <{$mail_from}>\r\n";
$headers .= "Reply-To: {$name} <{$email}>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

// ── Envoi ─────────────────────────────────────────────
//
//  O2switch : mail() utilise le MTA local (Postfix), aucune config SMTP requise.
//
//  ⬇ Pour passer à PHPMailer + SMTP O2switch (plus fiable) :
//    1. Dans le panneau cPanel O2switch, créez la boîte no-reply@allanmartin.fr
//    2. composer require phpmailer/phpmailer
//    3. Décommentez le bloc PHPMailer ci-dessous et commentez la ligne mail()
//
// ─────────────────────────────────────────────────────
// require __DIR__ . '/vendor/autoload.php';
// use PHPMailer\PHPMailer\PHPMailer;
// use PHPMailer\PHPMailer\Exception;
// try {
//     $mailer = new PHPMailer(true);
//     $mailer->isSMTP();
//     $mailer->Host       = 'mail.allanmartin.fr'; // serveur SMTP O2switch
//     $mailer->SMTPAuth   = true;
//     $mailer->Username   = 'no-reply@allanmartin.fr';
//     $mailer->Password   = 'MOT_DE_PASSE_SMTP';  // ← à remplacer
//     $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
//     $mailer->Port       = 465;
//     $mailer->CharSet    = 'UTF-8';
//     $mailer->setFrom('no-reply@' . SENDER_DOMAIN, 'Allan Martin');
//     $mailer->addAddress(RECIPIENT_EMAIL);
//     $mailer->addReplyTo($email, $name);
//     $mailer->Subject = '[Contact] ' . ($subject ?: 'Nouveau message');
//     $mailer->Body    = $mail_body;
//     $sent = $mailer->send();
// } catch (Exception $e) {
//     $sent = false;
//     error_log('PHPMailer error: ' . $e->getMessage());
// }
// ─────────────────────────────────────────────────────

$sent = mail($mail_to, $mail_subject, $mail_body, $headers);

if ($sent) {
    $_SESSION['last_contact_send'] = time();
    echo json_encode([
        'success' => true,
        'message' => 'Votre message a bien été envoyé. Je vous répondrai dans les plus brefs délais.',
    ]);
} else {
    error_log('contact form: mail() failed — recipient: ' . RECIPIENT_EMAIL);
    json_error(
        'Une erreur est survenue lors de l\'envoi. Veuillez réessayer ou me contacter directement.',
        500
    );
}
