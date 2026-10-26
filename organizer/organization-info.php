
<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (function_exists('authIsLoggedIn') && !authIsLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'] ?? [];
$userId = (int) ($user['id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../login.php');
    exit;
}

if (empty($_SESSION['organization_info_csrf'])) {
    $_SESSION['organization_info_csrf'] = bin2hex(random_bytes(32));
}

function orgInfoEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function orgInfoNormalizeUrl(string $value): string|false|null
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . $value;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    return $value;
}

$errors = [];
$successMessage = '';

try {
    $orgStmt = $pdo->prepare(
        'SELECT * FROM organizers WHERE user_id = :user_id LIMIT 1'
    );
    $orgStmt->execute(['user_id' => $userId]);
    $organization = $orgStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Organization profile load failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load the organization profile. Please check the server error log.');
}

if (!$organization) {
    http_response_code(404);
    exit(
        'No organization profile is linked to this account yet. ' .
        'Please complete organizer setup before editing this page.'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['organization_info_csrf'], $submittedToken)) {
        $errors[] = 'Your session token has expired. Refresh the page and try again.';
    } else {
        $businessName = trim((string) ($_POST['business_name'] ?? ''));
        $organizerType = trim((string) ($_POST['organizer_type'] ?? ''));
        $contactName = trim((string) ($_POST['contact_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $country = trim((string) ($_POST['country'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $businessAddress = trim((string) ($_POST['business_address'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        $websiteInput = trim((string) ($_POST['website'] ?? ''));
        $instagramInput = trim((string) ($_POST['instagram'] ?? ''));
        $facebookInput = trim((string) ($_POST['facebook'] ?? ''));
        $xInput = trim((string) ($_POST['x_url'] ?? ''));

        if ($businessName === '' || strlen($businessName) > 180) {
            $errors[] = 'Organization name is required and must be 180 characters or fewer.';
        }

        $allowedTypes = [
            'Company',
            'Event Agency',
            'Individual',
            'Non-profit',
            'Other'
        ];

        if ($organizerType !== '' && !in_array($organizerType, $allowedTypes, true)) {
            $errors[] = 'Choose a valid organizer type.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid support email address.';
        } elseif (strlen($email) > 190) {
            $errors[] = 'The support email is too long.';
        }

        if (strlen($phone) > 30) {
            $errors[] = 'The phone number must be 30 characters or fewer.';
        }

        if (strlen($country) > 100 || strlen($city) > 100) {
            $errors[] = 'Country and city must each be 100 characters or fewer.';
        }

        if (strlen($businessAddress) > 255) {
            $errors[] = 'The business address must be 255 characters or fewer.';
        }

        if (strlen($description) > 1500) {
            $errors[] = 'The description must be 1,500 characters or fewer.';
        }

        $website = orgInfoNormalizeUrl($websiteInput);
        $instagram = orgInfoNormalizeUrl($instagramInput);
        $facebook = orgInfoNormalizeUrl($facebookInput);
        $xUrl = orgInfoNormalizeUrl($xInput);

        foreach ([
            'Website' => $website,
            'Instagram' => $instagram,
            'Facebook' => $facebook,
            'X (Twitter)' => $xUrl
        ] as $label => $url) {
            if ($url === false) {
                $errors[] = $label . ' must be a valid URL.';
            } elseif (is_string($url) && strlen($url) > 255) {
                $errors[] = $label . ' URL must be 255 characters or fewer.';
            }
        }

        $newLogoUrl = $organization['logo_url'] ?? null;
        $uploadedLogoPath = null;

        if (
            isset($_FILES['organization_logo']) &&
            $_FILES['organization_logo']['error'] !== UPLOAD_ERR_NO_FILE
        ) {
            $file = $_FILES['organization_logo'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'The logo upload failed. Please try again.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'The logo must be 2 MB or smaller.';
            } elseif (!is_uploaded_file($file['tmp_name'])) {
                $errors[] = 'Invalid logo upload.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);

                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif'
                ];

                if (!isset($allowedMimes[$mime])) {
                    $errors[] = 'Use a JPG, PNG, WebP or GIF image for the logo.';
                } else {
                    $imageInfo = @getimagesize($file['tmp_name']);

                    if ($imageInfo === false) {
                        $errors[] = 'The uploaded file is not a valid image.';
                    } else {
                        $uploadDirectory = dirname(__DIR__) . '/uploads/organizer-logos';

                        if (
                            !is_dir($uploadDirectory) &&
                            !mkdir($uploadDirectory, 0755, true) &&
                            !is_dir($uploadDirectory)
                        ) {
                            $errors[] = 'Unable to prepare the logo upload directory.';
                        } else {
                            $fileName = bin2hex(random_bytes(16)) .
                                '.' . $allowedMimes[$mime];

                            $destination = $uploadDirectory . '/' . $fileName;

                            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                                $errors[] = 'Unable to save the logo. Check folder permissions.';
                            } else {
                                $uploadedLogoPath = $destination;
                                $newLogoUrl = '../uploads/organizer-logos/' . $fileName;
                            }
                        }
                    }
                }
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                $updateStmt = $pdo->prepare(
                    'UPDATE organizers SET
                        business_name = :business_name,
                        organizer_type = :organizer_type,
                        contact_name = :contact_name,
                        email = :email,
                        phone = :phone,
                        country = :country,
                        city = :city,
                        business_address = :business_address,
                        description = :description,
                        website = :website,
                        instagram = :instagram,
                        facebook = :facebook,
                        x_url = :x_url,
                        logo_url = :logo_url
                    WHERE id = :organization_id
                      AND user_id = :user_id'
                );

                $updateStmt->execute([
                    'business_name' => $businessName,
                    'organizer_type' => $organizerType ?: null,
                    'contact_name' => $contactName ?: null,
                    'email' => $email,
                    'phone' => $phone ?: null,
                    'country' => $country ?: null,
                    'city' => $city ?: null,
                    'business_address' => $businessAddress ?: null,
                    'description' => $description ?: null,
                    'website' => $website ?: null,
                    'instagram' => $instagram ?: null,
                    'facebook' => $facebook ?: null,
                    'x_url' => $xUrl ?: null,
                    'logo_url' => $newLogoUrl,
                    'organization_id' => (int) $organization['id'],
                    'user_id' => $userId
                ]);

                $pdo->commit();

                $orgStmt->execute(['user_id' => $userId]);
                $organization = $orgStmt->fetch(PDO::FETCH_ASSOC);

                $successMessage = 'Organization profile saved successfully.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($uploadedLogoPath && is_file($uploadedLogoPath)) {
                    unlink($uploadedLogoPath);
                }

                error_log('Organization profile update failed: ' . $e->getMessage());
                $errors[] = 'We could not save your changes. Check the server error log.';
            }
        }

        if ($errors) {
            // Preserve submitted text in the form when validation fails.
            $organization = array_merge($organization, [
                'business_name' => $businessName,
                'organizer_type' => $organizerType,
                'contact_name' => $contactName,
                'email' => $email,
                'phone' => $phone,
                'country' => $country,
                'city' => $city,
                'business_address' => $businessAddress,
                'description' => $description,
                'website' => $websiteInput,
                'instagram' => $instagramInput,
                'facebook' => $facebookInput,
                'x_url' => $xInput
            ]);
        }
    }
}

$settingsPageTitle = 'Organization Info';
$settingsBackUrl = 'settings.php';

$verificationItems = [
    'Email verified' => !empty($organization['email_verified_at']),
    'Phone number verified' => !empty($organization['phone_verified_at']),
    'Website verified' => !empty($organization['website_verified_at'])
];

$allVerified = !in_array(false, $verificationItems, true);

function orgInfoFieldValue(array $organization, string $key): string
{
    return orgInfoEscape($organization[$key] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organization Info | Ticketa</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #fff;
            color: #202020;
            font-family: Arial, Helvetica, sans-serif;
        }

        .organization-page { min-height: 100vh; }

        .organization-content {
            max-width: 1600px;
            margin: 0 auto;
            padding: 30px 3%;
        }

        .organization-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, .9fr);
            gap: 30px;
            align-items: start;
        }

        .organization-column {
            display: flex;
            flex-direction: column;
            gap: 20px;
            min-width: 0;
        }

        .profile-card {
            border: 1px solid #e7e7e7;
            border-radius: 12px;
            padding: 22px 24px;
            background: #fff;
            min-width: 0;
        }

        .profile-card h1,
        .profile-card h2 {
            margin: 0 0 24px;
            font-size: 21px;
            font-weight: 600;
            letter-spacing: -.35px;
        }

        .organization-logo-row {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 24px;
            min-width: 0;
        }

        .organization-logo-frame {
            width: 118px;
            height: 118px;
            border: 1px solid #bdbdbd;
            border-radius: 10px;
            padding: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: #fff;
        }

        .organization-logo-frame img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 5px;
        }

        .organization-logo-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            border-radius: 5px;
            background: #f4f7f6;
            color: #20b99a;
            font-size: 35px;
            font-weight: 700;
        }

        .organization-logo-info {
            min-width: 0;
        }

        .organization-logo-name {
            font-size: 20px;
            font-weight: 600;
            overflow-wrap: anywhere;
            margin-bottom: 8px;
        }

        .logo-upload-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #20b99a;
            color: #fff;
            border-radius: 6px;
            padding: 9px 13px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .logo-upload-label:hover { background: #149d82; }

        .logo-upload-label input { display: none; }

        .logo-help {
            margin: 8px 0 0;
            color: #626262;
            font-size: 12px;
            line-height: 1.5;
        }

        .form-field { margin-bottom: 17px; }
        .form-field:last-child { margin-bottom: 0; }

        .form-field label {
            display: block;
            margin-bottom: 9px;
            font-size: 15px;
            font-weight: 500;
        }

        .form-field input,
        .form-field select,
        .form-field textarea {
            width: 100%;
            min-height: 48px;
            border: 1.5px solid #333;
            border-radius: 6px;
            padding: 11px 12px;
            color: #333;
            background: #fafafa;
            font: inherit;
            font-size: 14px;
            outline: none;
        }

        .form-field textarea {
            min-height: 100px;
            resize: vertical;
            line-height: 1.5;
        }

        .form-field input:focus,
        .form-field select:focus,
        .form-field textarea:focus {
            border-color: #20b99a;
            box-shadow: 0 0 0 3px rgba(32, 185, 154, .12);
            background: #fff;
        }

        .description-counter {
            margin-top: 7px;
            text-align: right;
            color: #777;
            font-size: 12px;
        }

        .address-row,
        .contact-row {
            display: grid;
            grid-template-columns: minmax(115px, .65fr) minmax(0, 1.6fr);
            gap: 12px;
            align-items: center;
            margin-bottom: 16px;
        }

        .address-row:last-child,
        .contact-row:last-child { margin-bottom: 0; }

        .address-row > label,
        .contact-row > label {
            font-size: 14px;
            font-weight: 500;
        }

        .address-row input,
        .address-row select,
        .contact-row input {
            width: 100%;
            min-width: 0;
            min-height: 49px;
            border: 1.5px solid #333;
            border-radius: 6px;
            padding: 11px 12px;
            background: #fafafa;
            color: #333;
            font: inherit;
            font-size: 14px;
        }

        .address-row input:focus,
        .address-row select:focus,
        .contact-row input:focus {
            outline: 2px solid rgba(32, 185, 154, .25);
            border-color: #20b99a;
        }

        .verification-heading {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 22px;
        }

        .verification-heading h2 { margin: 0; }

        .verification-seal {
            color: #20b99a;
            font-size: 35px;
            line-height: 1;
        }

        .verification-main-label {
            font-weight: 600;
            font-size: 15px;
        }

        .verification-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 17px;
        }

        .verification-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #444;
            font-size: 14px;
        }

        .verification-icon {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
        }

        .verification-icon.verified {
            background: #20b765;
            color: #fff;
        }

        .verification-icon.pending {
            background: #ededed;
            color: #666;
        }

        .verification-note {
            color: #777;
            font-size: 12px;
            line-height: 1.5;
            margin: 19px 0 0;
        }

        .form-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 22px;
        }

        .button {
            border: 0;
            border-radius: 7px;
            padding: 12px 20px;
            cursor: pointer;
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }

        .button-primary {
            background: #20b99a;
            color: #fff;
        }

        .button-primary:hover { background: #149d82; }

        .button-secondary {
            background: #f2f2f2;
            color: #333;
        }

        .notice {
            margin-bottom: 20px;
            border-radius: 8px;
            padding: 13px 16px;
            font-size: 14px;
            line-height: 1.5;
        }

        .notice-success {
            background: #eaf8ef;
            color: #176c36;
            border: 1px solid #c6ead1;
        }

        .notice-error {
            background: #fff0f0;
            color: #a52828;
            border: 1px solid #f0cccc;
        }

        .notice ul { margin: 8px 0 0; padding-left: 20px; }

        .hidden-file-input { display: none; }

        @media (max-width: 1000px) {
            .organization-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .organization-content { padding: 22px 20px; }
        }

        @media (max-width: 600px) {
            .organization-content { padding: 16px 12px; }
            .profile-card { padding: 18px 15px; }
            .profile-card h1,
            .profile-card h2 { font-size: 19px; margin-bottom: 20px; }

            .organization-logo-frame {
                width: 90px;
                height: 90px;
            }

            .organization-logo-row { gap: 12px; }
            .organization-logo-name { font-size: 17px; }

            .address-row,
            .contact-row {
                grid-template-columns: 1fr;
                gap: 7px;
            }

            .address-row > label,
            .contact-row > label { font-size: 14px; }

            .form-actions {
                flex-direction: column-reverse;
                align-items: stretch;
            }

            .form-actions .button { text-align: center; }
        }
    </style>
</head>
<body>
<div class="organization-page">

    <?php include dirname(__DIR__) . '/includes/settings-topbar.php'; ?>

    <main class="organization-content">

        <?php if ($successMessage !== ''): ?>
            <div class="notice notice-success" role="status">
                <?= orgInfoEscape($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="notice notice-error" role="alert">
                <strong>Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= orgInfoEscape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST"
              action="organization-info.php"
              enctype="multipart/form-data">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= orgInfoEscape($_SESSION['organization_info_csrf']) ?>">

            <div class="organization-grid">

                <div class="organization-column">

                    <section class="profile-card">
                        <h1>Organization Info</h1>

                        <div class="organization-logo-row">
                            <div class="organization-logo-frame">
                                <img
                                    id="organizationLogoPreview"
                                    src="<?= orgInfoEscape($organization['logo_url'] ?: '') ?>"
                                    alt="Organization logo"
                                    style="<?= empty($organization['logo_url']) ? 'display:none;' : '' ?>"
                                >
                                <div
                                    class="organization-logo-placeholder"
                                    id="organizationLogoPlaceholder"
                                    style="<?= !empty($organization['logo_url']) ? 'display:none;' : '' ?>"
                                >T</div>
                            </div>

                            <div class="organization-logo-info">
                                <div class="organization-logo-name">
                                    <?= orgInfoFieldValue($organization, 'business_name') ?>
                                </div>

                                <label class="logo-upload-label">
                                    <span aria-hidden="true">↑</span>
                                    Upload New Logo
                                    <input
                                        type="file"
                                        name="organization_logo"
                                        id="organizationLogoInput"
                                        accept=".png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif"
                                    >
                                </label>

                                <p class="logo-help">
                                    PNG, JPG, WebP or GIF. Maximum 2 MB.
                                </p>
                            </div>
                        </div>

                        <div class="form-field">
                            <label for="business_name">Organization Name</label>
                            <input
                                id="business_name"
                                name="business_name"
                                type="text"
                                maxlength="180"
                                required
                                value="<?= orgInfoFieldValue($organization, 'business_name') ?>"
                            >
                        </div>

                        <div class="form-field">
                            <label for="organizer_type">Organizer Type</label>
                            <?php $currentType = $organization['organizer_type'] ?? ''; ?>
                            <select id="organizer_type" name="organizer_type">
                                <option value="">Select organizer type</option>
                                <?php foreach ([
                                    'Company',
                                    'Event Agency',
                                    'Individual',
                                    'Non-profit',
                                    'Other'
                                ] as $type): ?>
                                    <option
                                        value="<?= orgInfoEscape($type) ?>"
                                        <?= $currentType === $type ? 'selected' : '' ?>
                                    ><?= orgInfoEscape($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-field">
                            <label for="description">Description</label>
                            <textarea
                                id="description"
                                name="description"
                                maxlength="1500"
                                rows="4"
                            ><?= orgInfoFieldValue($organization, 'description') ?></textarea>
                            <div class="description-counter">
                                <span id="descriptionCount"><?= strlen((string) ($organization['description'] ?? '')) ?></span>
                                / 1500
                            </div>
                        </div>
                    </section>

                    <section class="profile-card">
                        <h2>Address</h2>

                        <div class="address-row">
                            <label for="country">Country</label>
                            <select id="country" name="country">
                                <option value="">Select country</option>
                                <?php foreach ([
                                    "Côte d'Ivoire",
                                    'Nigeria',
                                    'Ghana',
                                    'Senegal',
                                    'Benin',
                                    'Togo',
                                    'Burkina Faso',
                                    'Mali',
                                    'Cameroon',
                                    'Other'
                                ] as $countryOption): ?>
                                    <option
                                        value="<?= orgInfoEscape($countryOption) ?>"
                                        <?= ($organization['country'] ?? '') === $countryOption ? 'selected' : '' ?>
                                    ><?= orgInfoEscape($countryOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="address-row">
                            <label for="city">City / State</label>
                            <input
                                id="city"
                                name="city"
                                type="text"
                                maxlength="100"
                                value="<?= orgInfoFieldValue($organization, 'city') ?>"
                                placeholder="e.g. Abidjan"
                            >
                        </div>

                        <div class="address-row">
                            <label for="business_address">Business Address</label>
                            <input
                                id="business_address"
                                name="business_address"
                                type="text"
                                maxlength="255"
                                value="<?= orgInfoFieldValue($organization, 'business_address') ?>"
                                placeholder="Enter your business address"
                            >
                        </div>
                    </section>

                </div>

                <div class="organization-column">

                    <section class="profile-card">
                        <h2>Contact Details</h2>

                        <div class="contact-row">
                            <label for="email">Support Email</label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                maxlength="190"
                                required
                                value="<?= orgInfoFieldValue($organization, 'email') ?>"
                            >
                        </div>

                        <div class="contact-row">
                            <label for="phone">Phone Number</label>
                            <input
                                id="phone"
                                name="phone"
                                type="tel"
                                maxlength="30"
                                value="<?= orgInfoFieldValue($organization, 'phone') ?>"
                                placeholder="+225 ..."
                            >
                        </div>

                        <div class="contact-row">
                            <label for="website">Website</label>
                            <input
                                id="website"
                                name="website"
                                type="text"
                                maxlength="255"
                                value="<?= orgInfoFieldValue($organization, 'website') ?>"
                                placeholder="example.com"
                            >
                        </div>

                        <div class="contact-row">
                            <label for="instagram">Instagram</label>
                            <input
                                id="instagram"
                                name="instagram"
                                type="text"
                                maxlength="255"
                                value="<?= orgInfoFieldValue($organization, 'instagram') ?>"
                                placeholder="instagram.com/yourbrand"
                            >
                        </div>

                        <div class="contact-row">
                            <label for="facebook">Facebook</label>
                            <input
                                id="facebook"
                                name="facebook"
                                type="text"
                                maxlength="255"
                                value="<?= orgInfoFieldValue($organization, 'facebook') ?>"
                                placeholder="facebook.com/yourbrand"
                            >
                        </div>

                        <div class="contact-row">
                            <label for="x_url">X (Twitter)</label>
                            <input
                                id="x_url"
                                name="x_url"
                                type="text"
                                maxlength="255"
                                value="<?= orgInfoFieldValue($organization, 'x_url') ?>"
                                placeholder="x.com/yourbrand"
                            >
                        </div>
                    </section>

                    <section class="profile-card">
                        <div class="verification-heading">
                            <span class="verification-seal" aria-hidden="true">
                                <?= $allVerified ? '✹' : '◉' ?>
                            </span>
                            <div>
                                <h2>Verification Status</h2>
                                <div class="verification-main-label">
                                    <?= $allVerified ? 'Verified' : 'Verification pending' ?>
                                </div>
                            </div>
                        </div>

                        <ul class="verification-list">
                            <?php foreach ($verificationItems as $label => $isVerified): ?>
                                <li>
                                    <span class="verification-icon <?= $isVerified ? 'verified' : 'pending' ?>">
                                        <?= $isVerified ? '✓' : '–' ?>
                                    </span>
                                    <span>
                                        <?= orgInfoEscape($label) ?>
                                        <?= $isVerified ? '' : ' — Not verified' ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>

                            <li>
                                <span class="verification-icon pending">–</span>
                                <span>Social accounts — Verification not configured</span>
                            </li>
                        </ul>

                        <p class="verification-note">
                            Verification status reflects timestamps stored in the database.
                            Saving a profile or adding a link does not verify an account.
                        </p>
                    </section>

                </div>
            </div>

            <div class="form-actions">
                <a class="button button-secondary" href="settings.php">Cancel</a>
                <button class="button button-primary" type="submit">
                    Save Changes
                </button>
            </div>
        </form>
    </main>
</div>

<script>
(function () {
    const description = document.getElementById('description');
    const descriptionCount = document.getElementById('descriptionCount');
    const logoInput = document.getElementById('organizationLogoInput');
    const logoPreview = document.getElementById('organizationLogoPreview');
    const logoPlaceholder = document.getElementById('organizationLogoPlaceholder');

    if (description && descriptionCount) {
        description.addEventListener('input', function () {
            descriptionCount.textContent = description.value.length;
        });
    }

    if (logoInput && logoPreview && logoPlaceholder) {
        logoInput.addEventListener('change', function () {
            const file = logoInput.files && logoInput.files[0];

            if (!file) return;

            const allowedTypes = [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif'
            ];

            if (!allowedTypes.includes(file.type)) {
                alert('Please select a JPG, PNG, WebP or GIF image.');
                logoInput.value = '';
                return;
            }

            if (file.size > 2 * 1024 * 1024) {
                alert('The logo must be 2 MB or smaller.');
                logoInput.value = '';
                return;
            }

            const reader = new FileReader();

            reader.onload = function (event) {
                logoPreview.src = event.target.result;
                logoPreview.style.display = 'block';
                logoPlaceholder.style.display = 'none';
            };

            reader.readAsDataURL(file);
        });
    }
})();
</script>
</body>
</html>
