<?php
// --- PHP Logic ---
//作者：江硕虾肉
//博客：gz.jx1314.cc

// IMPORTANT: Replace with your actual Kuaidi100 credentials
$customer_id = 'xxx'; // 你的 Kuaidi100 customer ID
$api_key = 'xxx';         // 你的 Kuaidi100 API 密钥 (for the 'sign' parameter)

$api_url = 'https://poll.kuaidi100.com/poll/query.do';

$tracking_result = null;
$error_message = null;

// --- Language Handling ---
// Supported languages and their corresponding file names
$supported_langs = ['zh-CN', 'zh-TW', 'en'];
$default_lang = 'zh-CN'; // Default language

// Determine current language
$current_lang = $default_lang;
// Check for language parameter in GET (from language switcher links)
if (isset($_GET['lang']) && in_array($_GET['lang'], $supported_langs)) {
    $current_lang = $_GET['lang'];
    // Set a cookie to remember the user's choice
    setcookie('user_lang', $current_lang, time() + (86400 * 30), "/"); // Cookie lasts 30 days
} elseif (isset($_COOKIE['user_lang']) && in_array($_COOKIE['user_lang'], $supported_langs)) {
    // Check for language cookie if no GET parameter
    $current_lang = $_COOKIE['user_lang'];
}

// Include the language file
$lang_file = __DIR__ . '/lang/' . $current_lang . '.php'; // Use __DIR__ for safety
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    // Fallback to default language if the selected language file is missing
    error_log("Language file not found: " . $lang_file); // Log the error
    include __DIR__ . '/lang/' . $default_lang . '.php';
    $current_lang = $default_lang; // Reset current_lang to default
}

// --- Helper Functions (using the loaded $lang array) ---

// Helper function to map state code to text using the loaded language translations
function map_state_to_text($state_code, $lang) {
    return isset($lang['results']['status'][$state_code]) ? $lang['results']['status'][$state_code] : sprintf($lang['results']['unknown_status'], htmlspecialchars($state_code));
}

// --- Carrier Options (now keys, display names come from $lang['carriers']) ---
$carrier_options_codes = [
    'shunfeng', // SF BUY
    'dhl'       // DHL
];

// Build carrier options array with translated names for the dropdown
$carrier_options = [];
foreach($carrier_options_codes as $code) {
    // Use the translated name from the $lang array, fallback to code if translation missing
    $carrier_options[$code] = isset($lang['carriers'][$code]) ? $lang['carriers'][$code] : $code;
}


// --- Form Submission and API Query ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tracking_no = isset($_POST['tracking_no']) ? trim($_POST['tracking_no']) : '';
    $phone_no = isset($_POST['phone_no']) ? trim($_POST['phone_no']) : '';
    $carrier_com = isset($_POST['carrier']) ? trim($_POST['carrier']) : '';

    // Validate inputs using translated error messages
    if (empty($tracking_no)) {
        $error_message = $lang['errors']['missing_tracking_no'];
    } elseif (strlen($tracking_no) < 6 || strlen($tracking_no) > 32) {
        $error_message = $lang['errors']['invalid_tracking_length'];
    } elseif (empty($carrier_com) || !isset($carrier_options[$carrier_com])) { // Check against valid codes
        $error_message = $lang['errors']['missing_carrier'];
    } elseif ($carrier_com === 'shunfeng' && empty($phone_no)) {
        $error_message = $lang['errors']['sf_phone_required'];
    } else {
        // Map internal language codes to Kuaidi100 API language codes (usually just 'zh' and 'en')
        $api_lang_code = 'zh'; // Default to Chinese for API
        if ($current_lang === 'en') {
            $api_lang_code = 'en';
        }
        // Kuaidi100 API often only supports 'zh' and 'en', so we map both CN/TW to 'zh'

        $param_data = [
            'com' => $carrier_com,
            'num' => $tracking_no,
            'resultv2' => '4', // Request administrative area parsing and advanced status
            'show' => '0',     // JSON output
            'order' => 'desc',  // Descending order for results
            'lang' => $api_lang_code // Use the mapped API language code
        ];

        if (!empty($phone_no)) {
            $param_data['phone'] = $phone_no;
        }
        // Optional: Add 'from' and 'to' if needed
        // $param_data['from'] = '出发地城市';
        // $param_data['to'] = '目的地城市';


        // The 'param' field as a JSON string
        $param_json = json_encode($param_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Signature generation: param_json_string + api_key + customer_id, then MD5, then uppercase
        $sign_str = $param_json . $api_key . $customer_id;
        $sign = strtoupper(md5($sign_str));

        $post_fields = [
            'customer' => $customer_id,
            'sign' => $sign,
            'param' => $param_json
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields)); // Form-urlencoded
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        // For production, you might want to add timeout settings:
        // curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        // curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response_json = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_errno > 0) {
            // Use sprintf for placeholders in translated error message
            $error_message = sprintf($lang['errors']['curl_error'], $curl_errno, htmlspecialchars($curl_error));
        } else {
            $tracking_result = json_decode($response_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error_message = sprintf($lang['errors']['json_decode_error'], htmlspecialchars($response_json));
                $tracking_result = null;
            } elseif (isset($tracking_result['returnCode']) && $tracking_result['returnCode'] !== '200') {
                // Handle API specific errors using translated messages
                $error_message = sprintf($lang['errors']['api_return_code_error'], htmlspecialchars($tracking_result['returnCode']), htmlspecialchars($tracking_result['message']));
                if ($tracking_result['returnCode'] === '503'){
                     $error_message .= $lang['errors']['api_sign_error_hint'];
                } elseif ($tracking_result['returnCode'] === '601'){
                    $error_message .= $lang['errors']['api_quota_error_hint'];
                }
            } elseif (!isset($tracking_result['status']) || $tracking_result['status'] !== '200'){
                 // Handle general API errors or no data found cases
                 if(isset($tracking_result['message']) && $tracking_result['message'] !== 'ok'){
                    $error_message = sprintf($lang['errors']['api_general_error'], htmlspecialchars($tracking_result['message']));
                     if (isset($tracking_result['returnCode'])) {
                        $error_message .= sprintf($lang['errors']['api_code_hint'], htmlspecialchars($tracking_result['returnCode']));
                    }
                } elseif (!isset($tracking_result['data']) || empty($tracking_result['data'])){
                     $error_message = $lang['errors']['no_tracking_data'];
                     if(isset($tracking_result['message'])) { $error_message .= sprintf($lang['errors']['api_message_hint'], htmlspecialchars($tracking_result['message'])); }
                } else {
                     // Should not happen if status is 200 and message is ok, but data is empty? Defensive check.
                     $error_message = $lang['errors']['no_tracking_data'];
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>"> <!-- Set HTML lang attribute -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($lang['app']['title']); ?></title> <!-- Translated Title -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">

    <!-- Tailwind配置 (Remains the same) -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#9C27B0', // 紫色主色调，象征珠宝的高端感
                        secondary: '#E91E63', // 辅助色
                        accent: '#FFC107', // 强调色
                        neutral: {
                            100: '#F5F5F5',
                            200: '#EEEEEE',
                            300: '#E0E0E0',
                            400: '#BDBDBD',
                            500: '#9E9E9E',
                            600: '#757575',
                            700: '#616161',
                            800: '#424242',
                            900: '#212121',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                    boxShadow: {
                        'elegant': '0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02)',
                        'card': '0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 10px 10px -5px rgba(0, 0, 0, 0.01)',
                    }
                }
            }
        }
    </script>

    <!-- 自定义工具类 (Remains the same) -->
    <style type="text/tailwindcss">
        @layer utilities {
            .content-auto {
                content-visibility: auto;
            }
            .card-hover {
                @apply transition-all duration-300 hover:shadow-card hover:-translate-y-1;
            }
            .gradient-bg {
                background: linear-gradient(135deg, #6c91ff 0%, #005aff 100%);
            }
            .tracking-item-active {
                @apply border-l-4 border-primary bg-primary/5;
            }
            .animate-fadeIn {
                animation: fadeIn 0.5s ease-in-out;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
             /* Style for language switcher */
            .lang-switcher a {
                @apply text-neutral-600 hover:text-primary text-sm transition-colors duration-200 mx-1;
            }
            .lang-switcher a.active {
                 @apply font-bold text-primary underline;
            }
            /* Adjust color for switcher in header if needed */
             .gradient-bg .lang-switcher a {
                 @apply text-white/80 hover:text-white;
             }
             .gradient-bg .lang-switcher a.active {
                 @apply font-bold text-white underline;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-neutral-100 to-neutral-200 min-h-screen font-sans flex flex-col"> <!-- Added flex-col -->
    <div class="container mx-auto px-4 py-8 max-w-5xl flex-grow"> <!-- Added flex-grow -->

        <!-- 页面标题卡片 -->
        <div class="bg-white rounded-2xl shadow-elegant overflow-hidden mb-8 animate-fadeIn">
            <div class="gradient-bg p-6 text-white flex justify-between items-start md:items-center flex-col md:flex-row"> <!-- Adjusted flex for switcher -->
                <div>
                    <h1 class="text-[clamp(1.8rem,4vw,2.5rem)] font-bold flex items-center">
                        <i class="fa fa-diamond mr-3 text-accent"></i>
                        <?php echo htmlspecialchars($lang['app']['title']); ?> <!-- Translated Title -->
                    </h1>
                    <p class="text-white/80 mt-2"><?php echo htmlspecialchars($lang['app']['subtitle']); ?></p> <!-- Translated Subtitle -->
                </div>
                 <!-- Language Switcher -->
                <div class="mt-4 md:mt-0 text-left md:text-right w-full md:w-auto lang-switcher">
                     <?php
                     $lang_names = [
                         'zh-CN' => '简体中文',
                         'zh-TW' => '繁體中文',
                         'en' => 'English'
                     ];
                     foreach($supported_langs as $lang_code):
                         $active_class = ($lang_code === $current_lang) ? 'active' : '';
                         // Link back to the same page with the new lang parameter
                         $switch_url = htmlspecialchars($_SERVER['PHP_SELF']) . '?lang=' . htmlspecialchars($lang_code);
                     ?>
                         <a href="<?php echo $switch_url; ?>" class="<?php echo $active_class; ?>">
                             <?php echo htmlspecialchars($lang_names[$lang_code]); ?>
                         </a>
                     <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 查询表单卡片 -->
        <div class="bg-white rounded-2xl shadow-elegant p-6 mb-8 card-hover animate-fadeIn" style="animation-delay: 0.1s">
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . "?lang=" . $current_lang); ?>" class="space-y-6"> <!-- Keep lang param on postback -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label for="tracking_no" class="block text-sm font-medium text-neutral-700">
                            <i class="fa fa-barcode mr-2"></i><?php echo htmlspecialchars($lang['form']['tracking_no_label']); ?> <!-- Translated Label -->
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-neutral-500">
                                <i class="fa fa-search"></i>
                            </span>
                            <input type="text" id="tracking_no" name="tracking_no"
                                value="<?php echo isset($_POST['tracking_no']) ? htmlspecialchars($_POST['tracking_no']) : ''; ?>"
                                required minlength="6" maxlength="32"
                                class="block w-full pl-10 pr-3 py-3 border border-neutral-300 rounded-lg focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all duration-200"
                                placeholder="<?php echo htmlspecialchars($lang['form']['tracking_no_placeholder']); ?>"> <!-- Translated Placeholder -->
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label for="carrier" class="block text-sm font-medium text-neutral-700">
                            <i class="fa fa-truck mr-2"></i><?php echo htmlspecialchars($lang['form']['carrier_label']); ?> <!-- Translated Label -->
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-neutral-500 pointer-events-none">
                                <i class="fa fa-building"></i>
                            </span>
                            <select id="carrier" name="carrier" required
                                class="block w-full pl-10 pr-10 py-3 border border-neutral-300 rounded-lg focus:ring-2 focus:ring-primary/50 focus:border-primary appearance-none transition-all duration-200">
                                <option value=""><?php echo htmlspecialchars($lang['form']['carrier_placeholder']); ?></option> <!-- Translated Placeholder -->
                                <?php foreach ($carrier_options as $code => $name): // Use the $carrier_options array with translated names ?>
                                    <option value="<?php echo $code; ?>" <?php echo (isset($_POST['carrier']) && $_POST['carrier'] === $code) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($name); ?> <!-- Use the translated name -->
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                <i class="fa fa-chevron-down text-neutral-400"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <label for="phone_no" class="block text-sm font-medium text-neutral-700">
                        <i class="fa fa-phone mr-2"></i><?php echo htmlspecialchars($lang['form']['phone_label']); ?> <!-- Translated Label -->
                    </label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-neutral-500">
                            <i class="fa fa-mobile"></i>
                        </span>
                        <input type="text" id="phone_no" name="phone_no"
                            value="<?php echo isset($_POST['phone_no']) ? htmlspecialchars($_POST['phone_no']) : ''; ?>"
                            placeholder="<?php echo htmlspecialchars($lang['form']['phone_placeholder']); ?>"
                            class="block w-full pl-10 pr-3 py-3 border border-neutral-300 rounded-lg focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all duration-200">
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full gradient-bg text-white font-medium py-3 px-4 rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center">
                        <i class="fa fa-search mr-2"></i>
                        <?php echo htmlspecialchars($lang['form']['submit_button']); ?> <!-- Translated Button Text -->
                    </button>
                </div>
            </form>
        </div>

        <!-- 错误消息显示 -->
        <?php if ($error_message): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-8 animate-fadeIn" style="animation-delay: 0.2s">
                <div class="flex items-start">
                    <div class="flex-shrink-0 pt-0.5">
                        <i class="fa fa-exclamation-circle text-red-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">
                            <?php // Error message title might not need translation if the message itself is descriptive
                             // Or you could add a generic 'Error' title key to $lang['errors']
                             echo (isset($lang['errors']['error_title']) ? $lang['errors']['error_title'] : 'Error'); // Example: use a key if exists
                             ?>
                        </h3>
                        <div class="mt-1 text-sm text-red-700">
                            <?php echo $error_message; ?> <!-- $error_message is already translated -->
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 物流结果显示 -->
        <?php
        // Check if tracking_result is not null and seems like a successful response with data
        if (isset($tracking_result) && isset($tracking_result['status']) && $tracking_result['status'] === '200' && isset($tracking_result['data']) && !empty($tracking_result['data'])):
        ?>
            <div class="bg-white rounded-2xl shadow-elegant overflow-hidden mb-8 animate-fadeIn" style="animation-delay: 0.3s">
                <div class="p-6 border-b border-neutral-200">
                    <h2 class="text-xl font-bold text-neutral-800 flex items-center">
                        <i class="fa fa-map-marker-alt text-primary mr-3"></i>
                        <?php echo htmlspecialchars($lang['results']['details_title']); ?> <!-- Translated Title -->
                    </h2>
                </div>

                <!-- 物流摘要信息 -->
                <div class="p-6 border-b border-neutral-200 bg-neutral-50">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-2">
                            <p class="text-sm text-neutral-500"><?php echo htmlspecialchars($lang['results']['carrier_label']); ?></p> <!-- Translated Label -->
                            <p class="font-medium text-neutral-800">
                                <i class="fa fa-truck text-primary mr-2"></i>
                                <?php // Use the translated carrier name from the array
                                echo htmlspecialchars(isset($lang['carriers'][$tracking_result['com']]) ? $lang['carriers'][$tracking_result['com']] : $tracking_result['com']);
                                ?> (<?php echo htmlspecialchars($tracking_result['com']); ?>)
                            </p>
                        </div>
                        <div class="space-y-2">
                            <p class="text-sm text-neutral-500"><?php echo htmlspecialchars($lang['results']['tracking_no_label']); ?></p> <!-- Translated Label -->
                            <p class="font-medium text-neutral-800">
                                <i class="fa fa-barcode text-primary mr-2"></i>
                                <?php echo htmlspecialchars($tracking_result['nu']); ?>
                            </p>
                        </div>
                        <div class="space-y-2">
                            <p class="text-sm text-neutral-500"><?php echo htmlspecialchars($lang['results']['current_status_label']); ?></p> <!-- Translated Label -->
                            <p class="font-medium text-primary">
                                <i class="fa fa-info-circle mr-2"></i>
                                <?php echo htmlspecialchars(map_state_to_text($tracking_result['state'], $lang)); ?> <!-- Use translated status function -->
                            </p>
                        </div>
                    </div>

                   <?php if (isset($tracking_result['routeInfo'])): ?>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
                            <?php if (isset($tracking_result['routeInfo']['from']['name'])): ?>
                                <div class="space-y-2">
                                    <p class="text-sm text-neutral-500"><?php echo htmlspecialchars($lang['results']['origin_label']); ?></p> <!-- Translated Label -->
                                    <p class="font-medium text-neutral-800">
                                        <i class="fa fa-paper-plane text-primary mr-2"></i>
                                        <?php echo htmlspecialchars($tracking_result['routeInfo']['from']['name']); ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <?php if (isset($tracking_result['routeInfo']['cur']['name'])): ?>
                                <div class="space-y-2">
                                    <p class="text-sm text-neutral-500"><?php echo htmlspecialchars($lang['results']['current_city_label']); ?></p> <!-- Translated Label -->
                                    <p class="font-medium text-neutral-800">
                                        <i class="fa fa-map-marker text-primary mr-2"></i>
                                        <?php echo htmlspecialchars($tracking_result['routeInfo']['cur']['name']); ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <?php if (isset($tracking_result['routeInfo']['to']['name'])): ?>
                                <div class="space-y-2">
                                    <p class="text-sm text-neutral-500"><?php echo htmlspecialchars($lang['results']['destination_label']); ?></p> <!-- Translated Label -->
                                    <p class="font-medium text-neutral-800">
                                        <i class="fa fa-flag text-primary mr-2"></i>
                                        <?php echo htmlspecialchars($tracking_result['routeInfo']['to']['name']); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 物流轨迹信息 -->
                <div class="p-6">
                    <h3 class="text-lg font-medium text-neutral-800 mb-4"><?php echo htmlspecialchars($lang['results']['trajectory_title']); ?></h3> <!-- Translated Title -->

                    <div class="relative">
                        <!-- 垂直线 -->
                        <div class="absolute left-5 top-0 bottom-0 w-0.5 bg-neutral-200"></div>

                        <?php
                        $isFirst = true;
                        foreach ($tracking_result['data'] as $item):
                        ?>
                            <div class="tracking-item ml-12 mb-6 last:mb-0 relative card-hover
                                <?php echo $isFirst ? 'tracking-item-active' : ''; ?>">
                                <!-- 时间点标记 -->
                                <div class="absolute -left-12 top-0 w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center border-2 border-primary">
                                    <i class="fa fa-circle text-primary text-xs"></i>
                                </div>

                                <div class="p-4 rounded-lg border border-neutral-200">
                                    <div class="flex flex-col md:flex-row md:items-center justify-between mb-2">
                                        <!-- Kuaidi100 returns 'context' based on API lang param if available, otherwise original -->
                                        <div class="text-lg font-medium text-neutral-800">
                                            <?php echo htmlspecialchars($item['context']); ?>
                                        </div>
                                        <div class="text-sm text-neutral-500 mt-1 md:mt-0">
                                            <?php echo htmlspecialchars($item['ftime']); ?>
                                        </div>
                                    </div>

                                    <div class="status-info text-sm text-neutral-600 space-x-4 mt-3">
                                        <?php if (isset($item['status'])): ?>
                                            <span class="inline-flex items-center">
                                                <i class="fa fa-tag text-neutral-400 mr-1"></i>
                                                <?php // Display status text, potentially translated if Kuaidi100 doesn't translate 'status' field
                                                // Kuaidi100 docs suggest 'status' is the state code, not translated text.
                                                // 'context' is the translated description. So just display the code/name here.
                                                echo htmlspecialchars($item['status']);
                                                ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (isset($item['statusCode'])): ?>
                                            <span class="inline-flex items-center">
                                                <i class="fa fa-code text-neutral-400 mr-1"></i>
                                                <?php echo htmlspecialchars($item['statusCode']); ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (isset($item['areaName'])): ?>
                                            <span class="inline-flex items-center">
                                                <i class="fa fa-map-marker-alt text-neutral-400 mr-1"></i>
                                                <?php echo htmlspecialchars($item['areaName']); ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (isset($item['location'])): ?>
                                            <span class="inline-flex items-center">
                                                <i class="fa fa-location-arrow text-neutral-400 mr-1"></i>
                                                <?php echo htmlspecialchars($item['location']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php
                        $isFirst = false;
                        endforeach;
                        ?>
                    </div>
                </div>
            </div>
        <?php
        // Check if tracking_result is not null and message is ok but no data was returned
        // This handles cases where the API confirms the number format but has no info yet
        elseif (isset($tracking_result) && isset($tracking_result['message']) && $tracking_result['message'] === 'ok' && (!isset($tracking_result['data']) || empty($tracking_result['data']))): ?>
            <div class="bg-white rounded-2xl shadow-elegant p-8 mb-8 text-center animate-fadeIn" style="animation-delay: 0.3s">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-50 text-blue-500 mb-4">
                    <i class="fa fa-info-circle text-2xl"></i>
                </div>
                <h3 class="text-lg font-medium text-neutral-800 mb-2"><?php echo htmlspecialchars($lang['errors']['tracking_not_found_title']); ?></h3> <!-- Translated Title -->
                <p class="text-neutral-600 mb-6"><?php echo htmlspecialchars($lang['errors']['tracking_not_found_message']); ?></p> <!-- Translated Message -->
                <div class="flex justify-center">
                    <a href="javascript:history.back()" class="inline-flex items-center px-4 py-2 border border-neutral-300 rounded-lg shadow-sm text-sm font-medium text-neutral-700 bg-white hover:bg-neutral-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-all duration-200">
                        <i class="fa fa-arrow-left mr-2"></i>
                        <?php echo htmlspecialchars($lang['errors']['back_button']); ?> <!-- Translated Button -->
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <footer class="bg-neutral-800 text-white mt-auto" style="background-color:#3877fff2;padding:5px">
        <div class="container mx-auto px-4 max-w-5xl">
            <div class="flex flex-col md:flex-row justify-between items-center">

                <div class="text-neutral-400 text-sm" style="color:white">
                    &copy; <?php echo date("Y"); ?> <?php echo htmlspecialchars($lang['footer']['copyright']); ?> <!-- Translated Copyright -->
                </div>
            </div>
        </div>
    </footer>

    <!-- JavaScript -->
    <script>
        // 页面加载完成后执行
        document.addEventListener('DOMContentLoaded', function() {
            // 为表单元素添加动画效果
            const formInputs = document.querySelectorAll('input, select');
            formInputs.forEach(input => {
                if (input.value.trim() !== '') {
                    // Add a class for filled state if needed, 'border-primary' is applied on focus/blur now
                }

                input.addEventListener('focus', function() {
                    this.classList.add('border-primary');
                });

                input.addEventListener('blur', function() {
                    // Only remove border-primary if it's not required or if value is empty for required fields
                     const isRequiredAndEmpty = this.required && this.value.trim() === '';
                     const isNotRequiredAndEmpty = !this.required && this.value.trim() === '';

                    if (isNotRequiredAndEmpty || (isRequiredAndEmpty && !this.checkValidity())) {
                         this.classList.remove('border-primary');
                    }
                    // Note: HTML5 validation handles required field styling better
                });

                 // Initial check for selects and inputs with values on load
                 if (input.tagName === 'SELECT' && input.value !== '') {
                     input.classList.add('border-primary');
                 } else if (input.tagName !== 'SELECT' && input.value.trim() !== '') {
                     input.classList.add('border-primary');
                 }
            });

            // 为查询按钮添加点击效果
            const submitBtn = document.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.addEventListener('click', function() {
                    // Check if the form is valid before applying click effect
                    const form = this.closest('form');
                    if (form && form.checkValidity()) {
                         this.classList.add('opacity-90', 'scale-95');
                         setTimeout(() => {
                             this.classList.remove('opacity-90', 'scale-95');
                         }, 150);
                    }
                });
            }
        });
    </script>
</body>
</html>
