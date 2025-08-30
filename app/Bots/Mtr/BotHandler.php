<?php

declare(strict_types=1);

namespace App\Bots\Mtr;

use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class BotHandler
{
    // --- خصوصیات کلاس ---
    private Api $bot;
    private string $botLink;
    private string $text;
    private ?int $messageId;
    private array $message;
    private int $chatId;

    // آبجکت کاربر که پس از لود شدن، در تمام متدها در دسترس است
    private User $user;

    // نمونه‌های کلاس تطبیق‌دهنده برای کار با جداول مختلف
    private Jsondb $UDb; // Users Database
    private Jsondb $PDb; // Products Database
    private Jsondb $CDb; // Categories Database
    private Jsondb $IDb; // Invoices Database
    private Jsondb $SDb; // Settings Database

    /**
     * سازنده کلاس که با هر آپدیت جدید از طرف MtrBotHandler فراخوانی می‌شود
     */
    public function __construct(
        Api $bot,
        int $chatId,
        string $text,
        ?int $messageId,
        array $message,
        string $botLink
    ) {
        // --- مقداردهی اولیه خصوصیات ---
        $this->bot = $bot;
        $this->chatId = $chatId;
        $this->text = $text;
        $this->messageId = $messageId;
        $this->message = $message;
        $this->botLink = $botLink;

        // --- ساخت نمونه‌های لازم برای کار با دیتابیس از طریق مترجم Jsondb ---
        $this->UDb = new Jsondb('users');
        $this->PDb = new Jsondb('products');
        $this->CDb = new Jsondb('categories');
        $this->IDb = new Jsondb('invoices');
        $this->SDb = new Jsondb('settings');

        // --- پیدا کردن یا ساختن کاربر ---
        // با استفاده از مترجم، کاربر را بر اساس شناسه تلگرام او پیدا می‌کنیم
        $userObject = $this->UDb->get($this->chatId);

        if (!$userObject) {
            // اگر کاربر وجود نداشت، با اطلاعات پیام او را می‌سازیم
            $telegramUser = $this->message['from'];
            $this->UDb->insert([
                'id'         => $telegramUser['id'], // در Jsondb به telegram_id تبدیل می‌شود
                'first_name' => $telegramUser['first_name'],
                'last_name'  => $telegramUser['last_name'] ?? null,
                'username'   => $telegramUser['username'] ?? null,
                'name'       => $telegramUser['first_name'] . ' ' . ($telegramUser['last_name'] ?? ''),
            ]);
            // کاربر ساخته شده را دوباره از دیتابیس می‌خوانیم تا یک آبجکت کامل داشته باشیم
            $userObject = $this->UDb->get($this->chatId);
        }
        
        // آبجکت کاربر را در خصوصیت کلاس ذخیره می‌کنیم تا در همه جا در دسترس باشد
        $this->user = $userObject;
    }





    public function deleteMessage(int $messageId, int $delay = 0): bool
    {
        if (!$this->chatId || !$messageId) {
            return false;
        }
        if ($delay > 0) {
            sleep($delay);
        }

        try {
            return $this->bot->deleteMessage([
                'chat_id'    => $this->chatId,
                'message_id' => $messageId,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to delete message: ' . $e->getMessage(), [
                'chat_id' => $this->chatId,
                'message_id' => $messageId
            ]);
            return false;
        }
    }

    public function deleteMessages($messageIds = null): bool
    {
        if (empty($messageIds)) {
            $messageIdsjson = $this->user->message_ids;
            if (!empty($messageIds)) {
                $messageIds = json_decode($messageIdsjson, true);
            }
        }

        if (!$this->chatId || empty($messageIds) || count($messageIds) > 100) {
            return false;
        }

        try {
            $success = $this->bot->deleteMessages([
                'chat_id'     => $this->chatId,
                'message_ids' => $messageIds,
            ]);

            if ($success) {
                $this->UDb->unsetKey($this->chatId, 'message_ids');
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('Failed to delete messages: ' . $e->getMessage(), [
                'chat_id' => $this->chatId,
                'message_ids_count' => count($messageIds)
            ]);
            return false;
        }
    }
    public function handleRequest(): void
    {
        if (isset($this->message["from"])) {
            $this->saveOrUpdateUser($this->message["from"]);
        } else {
            error_log("BotHandler::handleRequest: 'from' field is missing.");
            return;
        }


        $state = $this->user->state ?? '';

        try {


            if (isset($this->message['web_app_data'])) {
                $webData = json_decode($this->message['web_app_data']['data'], true);

                // در اینجا، $webData حاوی سبد خرید نهایی شده توسط کاربر است
                // شما می‌توانید فرآیند پرداخت را با این داده‌ها ادامه دهید
                // برای مثال:
                $this->Alert("سبد خرید شما دریافت شد. در حال انتقال به مرحله پرداخت...");
                $this->initiateCardPaymentFromWebApp($webData); // یک تابع جدید برای این کار می‌سازیم

                return;
            }
            if (str_starts_with($state, 'awaiting_manual_quantity_')) {
                $productId = (int) str_replace('awaiting_manual_quantity_', '', $state);
                $newQuantity = trim($this->text);

                $this->deleteMessage($this->messageId);

                if (!is_numeric($newQuantity) || (int)$newQuantity < 0) {
                    $this->Alert("⚠️ لطفاً فقط یک عدد معتبر وارد کنید.");
                    return;
                }
                $newQuantity = (int) $newQuantity;
                $product = $this->PDb->get($productId);
                if (!$product) {
                    $this->Alert("خطا: محصول یافت نشد.");
                    $this->user->state = null;
                    $this->user->state_data = null;
                    $this->user->save();
                    return;
                }

                $stock = (int) $product->count;
                if ($newQuantity > $stock) {
                    $this->Alert("⚠️ تعداد درخواستی ({$newQuantity} عدد) بیشتر از موجودی انبار ({$stock} عدد) است.");
                    return;
                }

                $cart = json_decode($this->user->cart ?? '{}', true);
                if ($newQuantity > 0) {
                    $cart[$productId] = $newQuantity;
                } else {
                    unset($cart[$productId]);
                }

                $this->user->cart = json_encode($cart);

                $stateData = json_decode($this->user->state_data ?? '{}', true);
                $originalMessageId = $stateData['message_id'] ?? null;
                $isFromEditCart = $stateData['from_edit_cart'] ?? false;
                $this->user->state = null;
                $this->user->state_data = null;
                $this->user->save();

                if ($originalMessageId) {
                    if ($isFromEditCart) {
                        $this->refreshCartItemCard($productId, $originalMessageId);
                    } else {
                        $this->refreshProductCard($productId, $originalMessageId);
                    }
                    $this->Alert("✅ تعداد محصول در سبد خرید به‌روز شد.");
                }
                return;
            }
            if (str_starts_with($this->text, "/start")) {
                $this->deleteMessage($this->messageId);
                $this->user->state = null;
                $this->user->state_data = null;
                $this->user->save();

                $parts = explode(' ', $this->text);
                if (isset($parts[1]) && str_starts_with($parts[1], 'product_')) {
                    $productId = (int) str_replace('product_', '', $parts[1]);
                    $this->showSingleProduct($productId);
                } else {
                    $this->MainMenu();
                }
                return;
            } elseif ($this->text === "/mini_app") {
                $this->mini_app();
                return;
            } elseif ($this->text === "/cart") {
                $this->deleteMessages();
                $this->showCart();
                return;
            } elseif ($this->text === "/search") {
                $this->activateInlineSearch();
                return;
            } elseif ($this->text === "/favorites") {
                if (!empty($currentUser['message_ids']))
                    $this->deleteMessages();
                $this->showFavoritesList();
                return;
            } elseif (str_starts_with($state, 'awaiting_receipt_')) {
                $this->deleteMessage($this->messageId);
                if (!isset($this->message['photo'])) {
                    $this->Alert("خطا: لطفاً فقط تصویر رسید را ارسال کنید.");
                    return;
                }

                $invoiceId = str_replace('awaiting_receipt_', '', $state);
                $receiptFileId = end($this->message['photo'])['file_id'];

                $this->IDb->update($invoiceId, [
                    'receipt_file_id' => $receiptFileId,
                    'status' => 'payment_review'
                ]);

                $this->user->state = null; 
                $this->user->save();

                $this->Alert("✅ رسید شما با موفقیت دریافت شد. پس از بررسی، نتیجه به شما اطلاع داده خواهد شد. سپاس از خرید شما!");
                $this->notifyAdminOfNewReceipt($invoiceId, $receiptFileId);
                $this->MainMenu();

                return;
            } elseif (strpos($state, 'editing_product_') === 0) {
                $this->handleProductUpdate($state);
                return;
            } elseif (in_array($state, ['adding_product_name', 'adding_product_description', 'adding_product_count', 'adding_product_price', 'adding_product_photo'])) {
                $this->handleProductCreationSteps();
                return;
            } elseif (in_array($state, ['entering_shipping_name', 'entering_shipping_phone', 'entering_shipping_address'])) {
                $this->handleShippingInfoSteps();
                return;
            } elseif (str_starts_with($state, 'editing_category_name_')) {
                $categoryName = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($categoryName)) {
                    $this->Alert("نام دسته‌بندی نمی‌تواند خالی باشد.");
                    return;
                }
                $categoryId = str_replace('editing_category_name_', '', $state);
                if (!$categoryId) {
                    $this->Alert("خطا: شناسه دسته‌بندی مشخص نشده است.");
                    return;
                }
                $res = $this->CDb->update($categoryId, ['name' => $categoryName]);
                if ($res) {
                    $this->user->state = null; 
                    $this->user->save();

                    $messageId = $this->getMessageId($this->chatId);
                    $this->sendRequest("editMessageText", [
                        "chat_id" => $this->chatId,
                        "message_id" => $messageId,
                        "text" => "دسته‌بندی با موفقیت ویرایش شد: {$categoryName}",
                        "reply_markup" => [
                            "inline_keyboard" => [
                                [
                                    ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_category_' . $categoryId],
                                    ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_category_' . $categoryId]
                                ]
                            ]
                        ]
                    ]);
                } else {
                    $this->Alert("خطا در ویرایش دسته‌بندی. لطفاً دوباره تلاش کنید.");
                }
                return;
            } elseif ($state === "adding_category_name") {
                $categoryName = trim($this->text);
                if (empty($categoryName)) {
                    $this->Alert("نام دسته‌بندی نمی‌تواند خالی باشد.");
                    return;
                }
                $res = $this->createNewCategory($categoryName);
                $messageId = $this->getMessageId($this->chatId);
                if ($res) {
                    $this->deleteMessage($this->messageId);
                     $this->user->state = null; 
                    $this->user->save();

                    $this->Alert("دسته‌بندی جدید با موفقیت ایجاد شد.");
                    $this->showCategoryManagementMenu($messageId ?? null);
                } else {
                    $this->Alert("خطا در ایجاد دسته‌بندی. لطفاً دوباره تلاش کنید.");
                    $this->MainMenu($messageId ?? null);
                }
                return;
            } elseif (str_starts_with($state, 'editing_setting_')) {
                $key = str_replace('editing_setting_', '', $state);
                $value = trim($this->text);
                $this->deleteMessage($this->messageId);

                $numericFields = ['delivery_price', 'tax_percent', 'discount_fixed'];
                if (in_array($key, $numericFields) && !is_numeric($value)) {
                    $this->Alert("مقدار وارد شده باید یک عدد معتبر باشد.");
                    return;
                }

                $userData = $this->user->toArray();
                $stateData = json_decode($userData['state_data'] ?? '{}', true);
                $messageId = $stateData['message_id'] ?? null;

                $this->SDb->set($key, $value);

                $this->user->state = null;
                $this->user->state_data = null;
                $this->user->save();

                $this->showBotSettingsMenu($messageId);
                return;
            }
        } catch (\Throwable $th) {
            Log::error('BotHandler::handleRequest - message: ' . $th->getMessage(), [
                'chat_id' => $this->chatId,
                'text'    => $this->text,
            ]);
        }
    }


    public function handleCallbackQuery(array $callbackQuery): void
    {
        $chatId = $callbackQuery["message"]["chat"]["id"] ?? null;
        $messageId = $callbackQuery["message"]["message_id"] ?? $this->messageId;
        $callbackData = $callbackQuery["data"] ?? null;
        $callbackQueryId = $callbackQuery["id"] ?? null;

        if (!$chatId)
            return;
        if (isset($callbackQuery["from"])) {
            $this->saveOrUpdateUser($callbackQuery["from"]);
        }

        try {

            if ($callbackData === 'main_menu') {
                $messageIdsJson = $this->user->message_ids;
                if (!empty($messageIdsJson)) {
                    $messageIdsArray = json_decode($messageIdsJson, true);
                    $this->deleteMessages($messageIdsArray);
                }

                $this->MainMenu($messageId);
                return;
            } elseif ($callbackData === 'my_orders') {
                $this->showMyOrdersList(1, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'my_orders_page_')) {
                $page = (int) str_replace('my_orders_page_', '', $callbackData);
                $this->showMyOrdersList($page, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'show_order_details_')) {
                $invoiceId = str_replace('show_order_details_', '', $callbackData);
                $this->showSingleOrderDetails($invoiceId, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'show_order_summary_')) { // ▼▼▼ بلوک جدید ▼▼▼
                $invoiceId = str_replace('show_order_summary_', '', $callbackData);
                $this->showOrderSummaryCard($invoiceId, $messageId);
                return;
            } elseif ($callbackData === 'contact_support') {
                $this->showSupportInfo($messageId);
                return;
            } elseif ($callbackData === 'contact_support') {
                $this->showSupportInfo($messageId);
                return;
            } elseif ($callbackData === 'main_menu2') {
                $this->deleteMessage($this->messageId);
                $this->MainMenu();
                return;
            } elseif ($callbackData === 'nope') {
                return;
            } elseif ($callbackData === 'admin_bot_settings') {
                $this->showBotSettingsMenu($messageId);
                return;
            } elseif ($callbackData === 'show_store_rules') {
                $this->showStoreRules($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'edit_setting_')) {
                $key = str_replace('edit_setting_', '', $callbackData);

                $fieldMap = [
                    'store_name' => 'نام فروشگاه',
                    'main_menu_text' => 'متن منوی اصلی',
                    'delivery_price' => 'هزینه ارسال (به تومان)',
                    'tax_percent' => 'درصد مالیات (فقط عدد)',
                    'discount_fixed' => 'مبلغ تخفیف ثابت (به تومان)',
                    'card_number' => 'شماره کارت (بدون فاصله)',
                    'card_holder_name' => 'نام و نام خانوادگی صاحب حساب',
                    'support_id' => 'آیدی پشتیبانی تلگرام (با @)',
                    'store_rules' => 'قوانین فروشگاه (متن کامل)',
                    'channel_id' => 'آیدی کانال فروشگاه (با @)',
                ];

                if (!isset($fieldMap[$key])) {
                    $this->Alert("خطا: تنظیمات نامشخص است.");
                    return;
                }

                if (!isset($fieldMap[$key])) {
                    $this->Alert("خطا: تنظیمات نامشخص است.");
                    return;
                }

                $stateData = json_encode(['message_id' => $messageId]);
                $this->user->state = "editing_setting_{$key}";
                $this->user->state_data = $stateData;
                $this->user->save();

                $promptText = "لطفاً مقدار جدید برای \"{$fieldMap[$key]}\" را ارسال کنید.";
                $this->Alert($promptText, true);

                return;
            } elseif ($callbackData === 'activate_inline_search') {
                $this->activateInlineSearch($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'admin_approve_')) {
                $invoiceId = str_replace('admin_approve_', '', $callbackData);
                $invoice = $this->IDb->findById($invoiceId); // Use findById to be sure

                if (!$invoice || $invoice->status === 'approved') {
                    $this->Alert("این فاکتور قبلاً تایید شده یا یافت نشد.");
                    return;
                }

                $purchasedItems = json_decode($invoice->cart_items, true);

                if (is_array($purchasedItems)) {
                    foreach ($purchasedItems as $item) {
                        $productId = $item['id'];
                        $quantityPurchased = $item['quantity'];
                        $productData = $this->PDb->findById($productId); // Use findById to be sure

                        if ($productData) {
                            $newCount = $productData->count - $quantityPurchased;
                            $newCount = max(0, $newCount);
                            $this->PDb->update($productId, ['count' => $newCount]);
                        }
                    }
                }

                $this->IDb->update($invoiceId, ['status' => 'approved']);

                $user = $this->UDb->findById($invoice->user_id);

                if ($user) {
                    $this->sendRequest("sendMessage", [
                        'chat_id' => $user->telegram_id,
                        'text' => "✅ سفارش شما با شماره فاکتور `{$invoiceId}` تایید شد و به زودی برای شما ارسال خواهد شد. سپاس از خرید شما!",
                        'parse_mode' => 'HTML'
                    ]);
                }

                $originalText = $callbackQuery['message']['text'];
                $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $originalText . "\n\n--- ✅ این فاکتور توسط شما تایید شد. ---",
                    'parse_mode' => 'HTML'
                ]);

                return;
            } elseif (strpos($callbackData, 'admin_publish_product_') === 0) {
                $productId = (int) str_replace('admin_publish_product_', '', $callbackData);
                $product = DB::table('products')->findById($productId);

                if (!$product) {
                    $this->Alert("❌ محصول یافت نشد.");
                    return;
                }

                $success = $this->notifyChannelOfNewProduct($product);

                if ($success) {
                    $this->Alert("✅ محصول با موفقیت در کانال منتشر شد.");
                } else {
                    $settings = DB::table('settings')->all();
                    if (empty($settings['channel_id']) || !str_starts_with($settings['channel_id'], '@')) {
                        $this->Alert("❌ ابتدا باید آیدی کانال (با @) را در تنظیمات ربات ثبت کنید.", true);
                    } else {
                        $this->Alert("❌ خطا در انتشار محصول.", true);
                    }
                }
                return;
            } elseif (str_starts_with($callbackData, 'admin_reject_')) {
                $invoiceId = str_replace('admin_reject_', '', $callbackData);
                DB::table('invoices')->update($invoiceId, ['status' => 'rejected']);

                $invoice = DB::table('invoices')->findById($invoiceId);
                $userId = $invoice['user_id'] ?? null;
                $settings = DB::table('settings')->all();
                $supportId = $settings['support_id'] ?? 'پشتیبانی';

                if ($userId) {
                    $this->sendRequest("sendMessage", [
                        'chat_id' => $userId,
                        'text' => "❌ متاسفانه پرداخت شما برای فاکتور `{$invoiceId}` رد شد. لطفاً برای پیگیری با پشتیبانی ({$supportId}) تماس بگیرید.",
                        'parse_mode' => 'HTML'
                    ]);
                }

                $originalText = $callbackQuery['message']['text'];
                $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $originalText . "\n\n--- ❌ این فاکتور توسط شما رد شد. ---",
                    'parse_mode' => 'HTML'
                ]);

                return;
            } elseif ($callbackData === 'show_favorites') {
                $this->showFavoritesList(1, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'fav_list_page_')) {
                $page = (int) str_replace('fav_list_page_', '', $callbackData);
                $this->showFavoritesList($page, $messageId);
                return;
            } elseif ($callbackData === 'edit_cart') {
                $this->showCartInEditMode($messageId);
                return;
            } elseif ($callbackData === 'show_cart') {
                $this->showCart($messageId);
                return;
            } elseif ($callbackData === 'clear_cart') {
                DB::table('users')->update($this->chatId, ['cart' => '[]']);
                $this->Alert("🗑 سبد خرید شما با موفقیت خالی شد.");
                $this->showCart($messageId);
                return;
            } elseif ($callbackData === 'complete_shipping_info' || $callbackData === 'edit_shipping_info') {
                DB::table('users')->update($this->chatId, ['state' => 'entering_shipping_name', 'state_data' => '[]']);
                $res = $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "لطفاً نام و نام خانوادگی کامل گیرنده را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'show_cart']]]]
                ]);
                $this->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                return;
            } elseif ($callbackData === 'checkout') {
                $this->initiateCardPayment($messageId); // جایگزینی با تابع جدید
                return;
            } elseif (str_starts_with($callbackData, 'upload_receipt_')) {
                $invoiceId = str_replace('upload_receipt_', '', $callbackData);
                DB::table('users')->update($this->chatId, ['state' => 'awaiting_receipt_' . $invoiceId]);
                $this->Alert("لطفاً تصویر رسید خود را ارسال کنید...", true);
                return;
            } elseif (strpos($callbackData, 'admin_edit_product_') === 0) {
                sscanf($callbackData, "admin_edit_product_%d_cat_%d_page_%d", $productId, $categoryId, $page);
                if ($productId && $categoryId && $page) {
                    $this->showProductEditMenu($productId, $messageId, $categoryId, $page);
                }
                return;
            } elseif (str_starts_with($callbackData, 'confirm_product_edit_')) {
                sscanf($callbackData, "confirm_product_edit_%d_cat_%d_page_%d", $productId, $categoryId, $page);

                if (empty($productId) || empty($categoryId) || empty($page)) {
                    $this->Alert("خطا: اطلاعات ویرایش محصول ناقص است.");
                    return;
                }

                $product = DB::table('products')->findById($productId);
                if (empty($product)) {
                    $this->Alert("خطا: محصول یافت نشد.");
                    return;
                }

                DB::table('users')->update($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);

                $productText = $this->generateProductCardText($product);
                $originalKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page],
                            ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page]
                        ]
                    ]
                ];

                if (!empty($product['image_file_id'])) {
                    $this->sendRequest("editMessageCaption", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'caption' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $originalKeyboard
                    ]);
                } else {
                    $this->sendRequest("editMessageText", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $originalKeyboard
                    ]);
                }
                $this->Alert("✅ محصول با موفقیت ویرایش شد.", false);
                return;
            } elseif (strpos($callbackData, 'edit_field_') === 0) {
                sscanf($callbackData, "edit_field_%[^_]_%d_%d_%d", $field, $productId, $categoryId, $page);
                if ($field === 'imagefileid') {
                    $field = 'image_file_id';
                }

                $fieldMap = [
                    'name' => 'نام',
                    'description' => 'توضیحات',
                    'count' => 'تعداد',
                    'price' => 'قیمت',
                    'image_file_id' => 'عکس',
                    'category' => 'دسته‌بندی'
                ];

                if (!isset($fieldMap[$field])) {
                    $this->Alert("خطا: فیلد نامشخص است.");
                    return;
                }

                $product = DB::table('products')->findById($productId);
                if (!$product) {
                    $this->Alert("خطا: محصول برای ویرایش یافت نشد.");
                    return;
                }

                $method = !empty($product['image_file_id']) ? "editMessageCaption" : "editMessageText";
                $textOrCaptionKey = !empty($product['image_file_id']) ? "caption" : "text";


                $stateData = json_encode([
                    'product_id' => $productId,
                    'category_id' => $categoryId,
                    'page' => $page,
                    'message_id' => $messageId
                ]);

                if ($field === 'category') {
                    $allCategories = DB::table('categories')->all();
                    if (empty($allCategories)) {
                        $this->Alert("هیچ دسته‌بندی دیگری برای انتخاب وجود ندارد.");
                        return;
                    }

                    $categoryButtons = [];
                    foreach ($allCategories as $cat) {
                        $buttonText = ($cat['id'] == $categoryId) ? "✅ " . $cat['name'] : $cat['name'];
                        $categoryButtons[] = [['text' => $buttonText, 'callback_data' => 'update_product_category_' . $cat['id']]];
                    }
                    $categoryButtons[] = [['text' => '❌ انصراف', 'callback_data' => 'cancel_product_edit']];

                    $keyboard = ['inline_keyboard' => $categoryButtons];
                    $text = "لطفاً دسته‌بندی جدید محصول را انتخاب کنید:";

                    DB::table('users')->update($this->chatId, [
                        'state' => 'editing_product_category',
                        'state_data' => $stateData
                    ]);

                    $this->sendRequest($method, [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        $textOrCaptionKey => $text,
                        'reply_markup' => $keyboard
                    ]);
                    return;
                }

                if ($field === 'category') {
                    try {
                        $allCategories = DB::table('categories')->all();
                    } catch (\Throwable $e) {
                        Log::error("Failed to retrieve categories during product edit.", ['error' => $e->getMessage()]);
                        $this->Alert("خطا در بارگذاری لیست دسته‌بندی‌ها. لطفاً از صحت فایل دیتابیس دسته‌بندی‌ها مطمئن شوید.");
                        return;
                    }


                    if (empty($allCategories)) {
                        $this->Alert("هیچ دسته‌بندی دیگری برای انتخاب وجود ندارد.");
                        return;
                    }

                    $categoryButtons = [];
                    foreach ($allCategories as $cat) {
                        $buttonText = ($cat['id'] == $categoryId) ? "✅ " . $cat['name'] : $cat['name'];
                        $categoryButtons[] = [['text' => $buttonText, 'callback_data' => 'update_product_category_' . $cat['id']]];
                    }
                    $categoryButtons[] = [['text' => '❌ انصراف', 'callback_data' => 'cancel_product_edit']];

                    $keyboard = ['inline_keyboard' => $categoryButtons];
                    $text = "لطفاً دسته‌بندی جدید محصول را انتخاب کنید:";

                    DB::table('users')->update($this->chatId, [
                        'state' => 'editing_product_category',
                        'state_data' => $stateData
                    ]);

                    $this->sendRequest($method, [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        $textOrCaptionKey => $text,
                        'reply_markup' => $keyboard
                    ]);
                    return;
                }

                DB::table('users')->update($this->chatId, [
                    'state' => "editing_product_{$field}",
                    'state_data' => $stateData
                ]);

                $promptText = "لطفاً مقدار جدید برای \"{$fieldMap[$field]}\" را ارسال کنید.";


                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '❌ انصراف', 'callback_data' => 'cancel_product_edit']]
                    ]
                ];

                $this->sendRequest($method, [
                    'chat_id'      => $this->chatId,
                    'message_id'   => $messageId,
                    $textOrCaptionKey => $promptText,
                    'reply_markup' => $keyboard
                ]);
                return;
            } elseif (strpos($callbackData, 'update_product_category_') === 0) {
                $newCategoryId = (int) str_replace('update_product_category_', '', $callbackData);

                $user = DB::table('users')->findById($this->chatId);
                $stateData = json_decode($user['state_data'] ?? '{}', true);

                $productId = $stateData['product_id'] ?? null;
                $page = $stateData['page'] ?? null;
                $messageId = $stateData['message_id'] ?? null;

                if (!$productId || !$page || !$messageId) {
                    $this->Alert("خطا در پردازش اطلاعات. لطفاً مجدداً تلاش کنید.");
                    DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);
                    return;
                }

                DB::table('products')->update($productId, ['category_id' => $newCategoryId]);
                DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);

                $this->Alert("✅ دسته‌بندی محصول با موفقیت تغییر کرد.");
                $this->showProductEditMenu($productId, $messageId, $newCategoryId, $page);
                return;
            } elseif ($callbackData === 'cancel_product_edit') {
                $user = DB::table('users')->findById($this->chatId);
                $stateData = json_decode($user['state_data'] ?? '{}', true);

                $productId = $stateData['product_id'] ?? null;
                $categoryId = $stateData['category_id'] ?? null;
                $page = $stateData['page'] ?? null;
                $messageId = $stateData['message_id'] ?? null;

                if (!$productId || !$categoryId || !$page || !$messageId) {
                    $this->Alert("خطا در لغو عملیات. لطفاً دوباره تلاش کنید.");
                    DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);
                    return;
                }

                $product = DB::table('products')->findById($productId);
                if (!$product) {
                    $this->Alert("خطا: محصول یافت نشد.");
                    return;
                }

                DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);
                $this->showProductEditMenu($productId, $messageId, $categoryId, $page);
                return;
            } else if (strpos($callbackData, 'list_products_cat_') === 0) {
                sscanf($callbackData, "list_products_cat_%d_page_%d", $categoryId, $page);
                if ($categoryId && $page) {
                    $this->showProductListByCategory($categoryId, $page, $messageId);
                }
                return;
            } elseif (strpos($callbackData, 'product_creation_back_to_') === 0) {
                $targetStateName = str_replace('product_creation_back_to_', '', $callbackData);
                $targetState = 'adding_product_' . $targetStateName;
                if ($targetStateName === 'category') {
                    $this->promptForProductCategory($messageId);
                    return;
                }
                $user = DB::table('users')->findById($this->chatId);
                $stateData = json_decode($user['state_data'] ?? '{}', true);

                $creationSteps = ['name', 'description', 'count', 'price', 'image_file_id'];
                $targetIndex = array_search($targetStateName, $creationSteps);

                if ($targetIndex !== false) {
                    for ($i = $targetIndex; $i < count($creationSteps); $i++) {
                        unset($stateData[$creationSteps[$i]]);
                    }
                }


                DB::table('users')->update($this->chatId, [
                    'state' => $targetState,
                    'state_data' => json_encode($stateData)
                ]);

                $summaryText = $this->generateCreationSummaryText($stateData);
                $promptText = "";
                $reply_markup = [];

                switch ($targetState) {
                    case 'adding_product_name':
                        $promptText = "▶️ لطفاً <b>نام</b> محصول را مجدداً وارد کنید:";
                        // اصلاح کیبورد برای هماهنگی
                        $reply_markup = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_category'],
                                    ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                                ]
                            ]
                        ];
                        break;
                    case 'adding_product_description':
                        $promptText = "▶️ لطفاً <b>توضیحات</b> محصول را مجدداً وارد کنید:";
                        $reply_markup = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_name'],
                                    ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                                ]
                            ]
                        ];
                        break;
                    case 'adding_product_count':
                        $promptText = "▶️ لطفاً <b>تعداد موجودی</b> محصول را مجدداً وارد کنید (فقط عدد):";
                        $reply_markup = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_description'],
                                    ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                                ]
                            ]
                        ];
                        break;
                    case 'adding_product_price':
                        $promptText = "▶️ لطفاً <b>قیمت</b> محصول را مجدداً وارد کنید (به تومان و فقط عدد):";
                        $reply_markup = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_count'],
                                    ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                                ]
                            ]
                        ];
                        break;
                }

                $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $summaryText . $promptText,
                    'parse_mode' => 'HTML',
                    'reply_markup' => $reply_markup
                ]);
                return;
            } else if ($callbackData === 'product_confirm_save') {
                $user = DB::table('users')->findById($this->chatId);
                $stateData = json_decode($user['state_data'] ?? '{}', true);

                $this->createNewProduct($stateData);
                DB::table('users')->update($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);

                $this->Alert("✅ محصول با موفقیت ذخیره شد!");
                $this->deleteMessage($messageId); // پیام پیش‌نمایش را حذف کن
                $this->showProductManagementMenu(null); // منو را به عنوان پیام جدید بفرست

                return;
            } elseif ($callbackData === 'product_confirm_cancel') {
                DB::table('users')->update($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);
                $this->Alert("❌ عملیات افزودن محصول لغو شد.");
                $this->deleteMessage($messageId);
                $this->showProductManagementMenu(null);
                return;
            } elseif ($callbackData === 'admin_panel_entry') {
                $this->showAdminMainMenu($messageId);
                return;
            } elseif ($callbackData === 'admin_manage_invoices') {
                $this->showInvoiceManagementMenu($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'admin_list_invoices_')) {
                $parts = explode('_', $callbackData);

                $page = (int) array_pop($parts);
                array_pop($parts);
                $status = implode('_', array_slice($parts, 3));
                if ($status && $page) {

                    if (isset($callbackQuery['message']['photo'])) {
                        $this->deleteMessage($messageId);
                        $this->showInvoiceListByStatus($status, $page, null);
                    } else {
                        $this->showInvoiceListByStatus($status, $page, $messageId);
                    }
                }
                return;
            } elseif (str_starts_with($callbackData, 'admin_view_invoice:')) {
                $parts = explode(':', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                if (!empty($user['message_ids']))
                    $this->deleteMessages($user['message_ids']);

                if (count($parts) === 4) {
                    $invoiceId = $parts[1];
                    $fromStatus = $parts[2];
                    $fromPage = (int) $parts[3];
                    $this->showAdminInvoiceDetails($invoiceId, $fromStatus, $fromPage, $messageId);
                }
                return;
            } elseif ($callbackData === 'show_about_us') {
                $this->showAboutUs();
                return;
            } elseif ($callbackData === 'admin_manage_categories') {
                $this->showCategoryManagementMenu($messageId);
                return;
            } elseif ($callbackData === 'admin_category_list') {
                $this->showCategoryList($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'cart_remove_')) {
                $productId = (int) str_replace('cart_remove_', '', $callbackData);

                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);

                if (isset($cart[$productId])) {
                    unset($cart[$productId]);
                    DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                    $this->deleteMessage($messageId);
                    $this->Alert("محصول از سبد خرید شما حذف شد.", false);
                }

                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_increase_')) {
                $productId = (int) str_replace('edit_cart_increase_', '', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);
                if (isset($cart[$productId])) {
                    $cart[$productId]++;
                    DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                    $this->refreshCartItemCard($productId, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_decrease_')) {
                $productId = (int) str_replace('edit_cart_decrease_', '', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);
                if (isset($cart[$productId])) {
                    $cart[$productId]--;
                    if ($cart[$productId] <= 0)
                        unset($cart[$productId]);
                    DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                    $this->refreshCartItemCard($productId, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_remove_')) {
                $productId = (int) str_replace('edit_cart_remove_', '', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);
                unset($cart[$productId]);
                DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                $this->Alert("محصول از سبد خرید شما حذف شد.", false);
                $this->deleteMessage($messageId);

                return;
            } elseif (str_starts_with($callbackData, 'cart_increase_')) {
                $productId = (int) str_replace('cart_increase_', '', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);

                if (isset($cart[$productId])) {
                    $cart[$productId]++;
                    DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                    $this->refreshProductCard($productId, $messageId);
                    $this->Alert("✅ به سبد خرید اضافه شد", false);
                }
                return;
            } elseif (str_starts_with($callbackData, 'cart_decrease_')) {
                $productId = (int) str_replace('cart_decrease_', '', $callbackData);
                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);

                if (isset($cart[$productId])) {
                    $cart[$productId]--;
                    if ($cart[$productId] <= 0) {
                        unset($cart[$productId]);
                    }

                    DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                    $this->refreshProductCard($productId, $messageId);
                    $this->Alert("از سبد خرید کم شد", false);
                }
                return;
            } elseif (str_starts_with($callbackData, 'category_')) {
                $categoryId = (int) str_replace('category_', '', $callbackData);
                $this->showUserProductList($categoryId, 1, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'user_list_products_cat_')) {
                sscanf($callbackData, "user_list_products_cat_%d_page_%d", $categoryId, $page);
                if ($categoryId && $page) {
                    $this->showUserProductList($categoryId, $page, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'toggle_favorite_')) {
                $productId = (int) str_replace('toggle_favorite_', '', $callbackData);
                $product = DB::table('products')->findById($productId);

                if (!$product) {
                    $this->Alert("❌ محصول یافت نشد.");
                    return;
                }

                $user = DB::table('users')->findById($this->chatId);
                $favorites = json_decode($user['favorites'] ?? '[]', true);

                $message = "";

                if (in_array($productId, $favorites)) {
                    $favorites = array_diff($favorites, [$productId]);
                    $message = "از علاقه‌مندی‌ها حذف شد.";
                } else {
                    $favorites[] = $productId;
                    $message = "به علاقه‌مندی‌ها اضافه شد.";
                }
                DB::table('users')->update($this->chatId, ['favorites' => json_encode(array_values($favorites))]);

                $this->refreshProductCard($productId, $messageId);
                $this->Alert("❤️ " . $message, false);

                return;
            } elseif (str_starts_with($callbackData, 'add_to_cart_')) {
                $productId = (int) str_replace('add_to_cart_', '', $callbackData);
                $product = DB::table('products')->findById($productId);

                if (!$product || ($product['count'] ?? 0) <= 0) {
                    $this->Alert("❌ متاسفانه موجودی این محصول به اتمام رسیده است.");
                    return;
                }

                $user = DB::table('users')->findById($this->chatId);
                $cart = json_decode($user['cart'] ?? '{}', true);

                if (isset($cart[$productId])) {
                    $cart[$productId]++;
                } else {
                    $cart[$productId] = 1;
                }

                DB::table('users')->update($this->chatId, ['cart' => json_encode($cart)]);
                $this->Alert("✅ به سبد خرید اضافه شد", false);
                $this->refreshProductCard($productId, $messageId);

                return;
            } elseif (strpos($callbackData, 'manual_quantity_') === 0) {

                $isFromEditCart = str_ends_with($callbackData, '_cart');

                $cleanCallbackData = str_replace('_cart', '', $callbackData);
                $productId = (int) str_replace('manual_quantity_', '', $cleanCallbackData);

                $product = DB::table('products')->findById($productId);
                if (!$product) {
                    $this->Alert("خطا: محصول یافت نشد.");
                    return;
                }
                $method = !empty($product['image_file_id']) ? "editMessageCaption" : "editMessageText";
                $textOrCaptionKey = !empty($product['image_file_id']) ? "caption" : "text";

                DB::table('users')->update($this->chatId, [
                    'state' => 'awaiting_manual_quantity_' . $productId,
                    // زمینه (context) را در state_data ذخیره می‌کنیم
                    'state_data' => json_encode([
                        'message_id' => $messageId,
                        'from_edit_cart' => $isFromEditCart
                    ])
                ]);

                $productName = $product['name'];
                $promptText = "«چند عدد از محصول \"{$productName}\" را می‌خواهید در سبد خرید خود داشته باشید؟»\n\n"
                    . "لطفاً فقط عدد ارسال کنید 😊 (ارسال 0 برای حذف)";

                $this->sendRequest($method, [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    $textOrCaptionKey => $promptText,
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [['text' => '❌ انصراف', 'callback_data' => 'cancel_manual_quantity_' . $productId . ($isFromEditCart ? '_cart' : '')]]
                        ]
                    ]
                ]);
                return;
            } elseif (strpos($callbackData, 'cancel_manual_quantity_') === 0) {
                $isFromEditCart = str_ends_with($callbackData, '_cart');
                $cleanCallbackData = str_replace('_cart', '', $callbackData);
                $productId = (int) str_replace('cancel_manual_quantity_', '', $cleanCallbackData);

                DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);

                if ($isFromEditCart) {
                    $this->refreshCartItemCard($productId, $messageId);
                } else {
                    $this->refreshProductCard($productId, $messageId);
                }
                return;
            } elseif (strpos($callbackData, 'admin_edit_category_') === 0) {
                $categoryId = str_replace('admin_edit_category_', '', $callbackData);
                $category = DB::table('categories')->findById($categoryId);
                if ($category) {
                    DB::table('users')->update($this->chatId, ['state' => "editing_category_name_{$categoryId}"]);
                    $res = $this->sendRequest("editMessageText", [
                        "chat_id" => $this->chatId,
                        "message_id" => $messageId,
                        "text" => "لطفاً نام جدید دسته‌بندی را وارد کنید: {$category['name']}",
                        "reply_markup" =>
                        [
                            "inline_keyboard" => [
                                [["text" => "🔙 بازگشت", "callback_data" => "admin_manage_categories"]]
                            ]
                        ]
                    ]);
                    $this->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                } else {
                    $this->alert("دسته‌بندی یافت نشد.");
                }
            } elseif (strpos($callbackData, 'admin_delete_category_') === 0) {
                $categoryId = str_replace('admin_delete_category_', '', $callbackData);
                $category = DB::table('categories')->findById($categoryId);
                if (!$category) {
                    $this->alert("دسته‌بندی یافت نشد.");
                    return;
                }
                $res = DB::table('categories')->delete($categoryId);
                if ($res) {
                    $this->Alert("دسته‌بندی با موفقیت حذف شد.");
                    $this->deleteMessage($messageId);
                } else {
                    $this->Alert("خطا در حذف دسته‌بندی. لطفاً دوباره تلاش کنید.");
                }
            } elseif (strpos($callbackData, 'product_cat_select_') === 0) {
                $categoryId = (int) str_replace('product_cat_select_', '', $callbackData);

                $category = DB::table('categories')->findById($categoryId);
                $categoryName = $category ? $category['name'] : 'نامشخص';

                $stateData = [
                    'category_id' => $categoryId,
                    'category_name' => $categoryName
                ];

                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_name',
                    'state_data' => json_encode($stateData)
                ]);

                $summaryText = $this->generateCreationSummaryText($stateData);
                $promptText = "▶️ حالا لطفاً <b>نام</b> محصول را وارد کنید:";

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_category'],
                            ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                        ]
                    ]
                ];
                $res = $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $summaryText . $promptText,
                    'parse_mode' => 'HTML',
                    'reply_markup' => $keyboard
                ]);
                $this->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                return;
            } elseif ($callbackData === 'admin_manage_products') {
                $user = DB::table('users')->findById($this->chatId);
                if ($user['state'] != null) {
                    DB::table('users')->update($this->chatId, ['state' => null, 'state_data' => null]);
                }
                $this->showProductManagementMenu($messageId);
            } elseif ($callbackData === 'admin_add_product') {
                $this->promptForProductCategory($messageId);
            } elseif ($callbackData === 'admin_product_list') {
                $this->promptUserForCategorySelection($messageId);
            } elseif (strpos($callbackData, 'admin_delete_product_') === 0) {

                sscanf($callbackData, "admin_delete_product_%d_cat_%d_page_%d", $productId, $categoryId, $page);
                $product = DB::table('products')->findById($productId);

                if (!$product) {
                    $this->Alert("خطا: محصول یافت نشد!");
                    return;
                }

                $confirmationText = "❓ آیا از حذف محصول \"{$product['name']}\" مطمئن هستید؟";
                $confirmationKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ بله، حذف کن', 'callback_data' => 'confirm_delete_product_' . $productId],
                            ['text' => '❌ خیر، انصراف', 'callback_data' => 'cancel_delete_product_' . $productId . '_cat_' . $categoryId . '_page_' . $page]
                        ]
                    ]
                ];

                if (!empty($product['image_file_id'])) {
                    $this->sendRequest("editMessageCaption", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'caption' => $confirmationText,
                        'reply_markup' => $confirmationKeyboard
                    ]);
                } else {
                    $this->sendRequest("editMessageText", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => $confirmationText,
                        'reply_markup' => $confirmationKeyboard
                    ]);
                }
                return;
            } elseif (strpos($callbackData, 'confirm_delete_product_') === 0) {
                $productId = str_replace('confirm_delete_product_', '', $callbackData);

                DB::table('products')->delete($productId);
                $this->deleteMessage($messageId);
                $this->Alert("✅ محصول با موفقیت حذف شد.");
                return;
            } elseif (strpos($callbackData, 'cancel_delete_product_') === 0) {

                sscanf($callbackData, "cancel_delete_product_%d_cat_%d_page_%d", $productId, $categoryId, $page);
                $product = DB::table('products')->findById($productId);

                if (!$product || !$categoryId || !$page) {
                    $this->Alert("خطا در بازگردانی محصول.");
                    return;
                }

                $productText = $this->generateProductCardText($product);

                $originalKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page],
                            ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page]
                        ]
                    ]
                ];
                if (!empty($product['image_file_id'])) {
                    $this->sendRequest("editMessageCaption", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'caption' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $originalKeyboard
                    ]);
                } else {
                    $this->sendRequest("editMessageText", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $originalKeyboard
                    ]);
                }

                return;
            } elseif ($callbackData === 'admin_reports') {
                $this->Alert("این بخش هنوز آماده نیست.");
            } elseif ($callbackData === 'admin_add_category') {
                DB::table('users')->update($this->chatId, ['state' => 'adding_category_name']);
                $res = $this->sendRequest("editMessageText", [
                    "chat_id" => $this->chatId,
                    "message_id" => $messageId,
                    "text" => "لطفاً نام دسته‌بندی جدید را وارد کنید:",
                    "reply_markup" =>
                    [
                        "inline_keyboard" => [
                            [["text" => "🔙 بازگشت", "callback_data" => "admin_panel_entry"]]
                        ]
                    ]
                ]);
                $this->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
            } else {
                $this->sendRequest("answerCallbackQuery", [
                    "callback_query_id" => $this->callbackQueryId,
                    "text" => "در حال پردازش درخواست شما..."
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('BotHandler::handleCallbackQuery - message: ' . $th->getMessage(), [
                'callbackQuery' => $callbackQuery,
            ]);
            return;
        }



        $this->sendRequest('answerCallbackQuery', ['callback_query_id' => $this->callbackQueryId]);
    }
    public function handleInlineQuery(array $inlineQuery): void
    {
        $inlineQueryId = $inlineQuery['id'];
        $query = trim($inlineQuery['query']);
        if (empty($query)) {
            $this->sendRequest("answerInlineQuery", ['inline_query_id' => $inlineQueryId, 'results' => []]);
            return;
        }

        $allProducts = DB::table('products')->all();
        $foundProducts = [];
        foreach ($allProducts as $product) {
            if (str_contains(strtolower($product['name']), strtolower($query)) || str_contains(strtolower($product['description']), strtolower($query))) {
                $foundProducts[] = $product;
            }
        }

        $foundProducts = array_slice($foundProducts, 0, 20);

        $results = [];
        foreach ($foundProducts as $product) {
            $productUrl = $this->botLink . 'product_' . $product['id'];

            $results[] = [
                'type' => 'article',
                'id' => (string) $product['id'],
                'title' => $product['name'],
                'input_message_content' => [
                    'message_text' => $this->generateProductCardText($product),
                    'parse_mode' => 'HTML'
                ],

                'reply_markup' => [
                    'inline_keyboard' => [
                        [['text' => 'مشاهده در ربات', 'url' => $productUrl]]
                    ]
                ],
                'description' => 'قیمت: ' . number_format($product['price']) . ' تومان'

            ];
        }

        $this->sendRequest("answerInlineQuery", [
            'inline_query_id' => $inlineQueryId,
            'results' => $results,
            'cache_time' => 10
        ]);
    }


    public function MainMenu($messageId = null): void
    {
        $user      = DB::table('users')->findById($this->chatId);
        $settings  = DB::table('settings')->all();
        $channelId = $settings['channel_id'] ?? null;

        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }


        $hour      = (int) jdf::jdate('H', '', '', '', 'en');
        $firstName = trim((string)($user['first_name'] ?? ''));
        $greetName = $firstName !== '' ? ' ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') : '';
        $storeName = (string)($settings['store_name'] ?? 'فروشگاه MTR');

        $dayName     = jdf::jdate('l');
        $isFriday    = mb_strpos($dayName, 'جمعه') !== false;
        $isThursday  = mb_strpos($dayName, 'پنجشنبه') !== false;
        $weekendMode = $settings['weekend_mode'] ?? 'fri';
        $isWeekend   = ($weekendMode === 'thu_fri') ? ($isThursday || $isFriday) : $isFriday;

        $todayJalali = jdf::jdate('Y-m-d', '', '', '', 'en');
        $holidaysRaw = $settings['holidays'] ?? '[]';
        $holidaysArr = is_array($holidaysRaw) ? $holidaysRaw : (json_decode((string) $holidaysRaw, true) ?: []);
        $normalizedHolidays = array_map(fn($d) => str_replace('/', '-', trim((string) $d)), $holidaysArr);
        $isHoliday   = in_array($todayJalali, $normalizedHolidays, true);

        $period = match (true) {
            $hour < 5  => 'late_night',
            $hour < 7  => 'pre_morning',
            $hour < 12 => 'morning',
            $hour < 14 => 'noon',
            $hour < 18 => 'afternoon',
            $hour < 22 => 'evening',
            default    => 'night',
        };

        // آرایه‌های پیام‌ها
        $messagesDefault = [
            'late_night'  => ["🌌 شب‌زنده‌داری می‌کنی$greetName یه سر به پیشنهادهای خاص {$storeName} بزن!", "✨ نیمه‌شب بخیر$greetName! تخفیف‌های آروم و دلبرانه منتظرته."],
            'pre_morning' => ["🌅 صبح زود بخیر$greetName! شروع درخشان با انتخاب‌های {$storeName}.", "☕ سلام$greetName! قهوه‌تو نوش جان—ما پیشنهادهای امروز رو آماده کردیم 💎"],
            'morning'     => ["☀️ صبح بخیر$greetName! آماده‌ای چند پیشنهاد ویژه امروز رو ببینی؟", "🌼 صبحِ نایس$greetName! سبدت منتظر یک آیتم درخشانه 💎"],
            'noon'        => ["🍽️ ظهر بخیر$greetName! قبل از ناهار یه نگاه به پیشنهادهای داغ بنداز.", "⏰ وقت استراحت: ۲ دقیقه تورِ پیشنهادهای {$storeName}؟"],
            'afternoon'   => ["🌿 عصر بخیر$greetName! یه انتخاب شیک برای خونه‌ات پیدا کنیم؟", "🎁 عصرونه‌ی جذاب: پیشنهادهای محدود امروز رو از دست نده."],
            'evening'     => ["🌇 غروب خوش$greetName! آماده‌ای برای کشف انتخاب‌های امشب؟", "🕯️ نور ملایم شب + یه آیتم جدید = حال‌خوب خونه."],
            'night'       => ["🌙 شب بخیر$greetName! شاید وقتشه یه هدیه‌ خاص برای خودت پیدا کنی…", "⭐ شبانه‌های {$storeName}: انتخاب‌های آروم و شیک."],
        ];
        $messagesWeekend = [
            'morning'   => ["🎉 آخر هفته بخیر$greetName! بذار روزت رو با یه انتخاب درخشان شروع کنیم 💎", "☀️ صبحِ آخر هفته‌ات پر از حال خوب—این مدل‌ها حسابی به دکور می‌چسبن."],
            'afternoon' => ["🌿 عصرِ آخر هفته$greetName! وقت یه تغییر ریز و شیکه.", "🛍️ پرفروش‌های این هفته رو آخر هفته امتحان کن!"],
            'evening'   => ["🌇 غروب آخر هفته مبارک$greetName! یه آیتم تازه برای حال‌وهوای خونه؟", "🕯️ نور ملایم شب + خرید دلنشین = آخر هفته‌ی جذاب."],
            'night'     => ["🌙 شب آرومِ آخر هفته$greetName! آماده‌ای برای یه انتخاب خاص؟", "⭐ آخر هفته رو با یه خرید حساب‌شده قشنگ‌تر کن."],
            'any'       => ["🎉 آخر هفته رسید! پیشنهادهای ویژه رو از دست نده.", "💎 ویژه‌های آخر هفته فعال شد—نگاه کن و انتخاب کن!"],
        ];
        $messagesHoliday = [
            'morning'   => ["🎊 تعطیلات مبارک$greetName! چند مدل تازه برای شروع روز آماده‌ست.", "☀️ صبحِ تعطیل خوش! بپر سراغ پیشنهادهای سبک و شیک امروز."],
            'afternoon' => ["🌤️ ظهرِ تعطیل خوش$greetName! گزینه‌های داغ امروز منتظر توئن.", "🎁 تعطیلی = زمانِ عالی برای انتخاب هدیه."],
            'evening'   => ["🌆 عصر تعطیل دل‌انگیز$greetName! آماده‌ی کشف مدل‌های امشب؟", "🕯️ با یه آیتم تازه، فضای خونه رو جشن‌طور کن."],
            'night'     => ["🌙 شبِ تعطیلات شیرین! یه انتخاب خاص برای پایان روز؟", "⭐ تعطیلات با یه خرید کوچیک قشنگ‌تر می‌شه."],
            'any'       => ["🎊 تعطیلات مبارک! ویژه‌های امروز رو ببین.", "💎 پیشنهادهای مخصوص تعطیلات فعال شد!"],
        ];

        $context = $isHoliday ? 'holiday' : ($isWeekend ? 'weekend' : 'default');
        $pool = match ($context) {
            'holiday' => $messagesHoliday[$period] ?? ($messagesHoliday['any'] ?? $messagesDefault[$period]),
            'weekend' => $messagesWeekend[$period] ?? ($messagesWeekend['any'] ?? $messagesDefault[$period]),
            default   => $messagesDefault[$period],
        };
        $welcomeMessage = $pool[array_rand($pool)];

        $menuText = !empty($settings['main_menu_text'])
            ? $settings['main_menu_text'] . "\n\n" . "<blockquote>{$welcomeMessage}</blockquote>"
            : $welcomeMessage;

        $buttons = [];

        if (!empty($settings['daily_offer'])) {
            $buttons[] = [['text' => '🔥 پیشنهاد ویژه امروز', 'callback_data' => 'daily_offer']];
        }

        $allCategories = DB::table('categories')->all();
        if (!empty($allCategories)) {
            $activeCategories = array_filter($allCategories, fn($cat) => isset($cat['parent_id']) && (int)$cat['parent_id'] === 0 && !empty($cat['is_active']));
            usort($activeCategories, fn($a, $b) => (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0));
            $row = [];
            foreach ($activeCategories as $category) {
                $row[] = ['text' => (string)$category['name'], 'callback_data' => 'category_' . (int)$category['id']];
                if (count($row) === 2) {
                    $buttons[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) {
                $buttons[] = $row;
            }
        }

        $staticButtons = [
            [['text' => '❤️ علاقه‌مندی‌ها', 'callback_data' => 'show_favorites'], ['text' => '🛒 سبد خرید', 'callback_data' => 'show_cart']],
            [['text' => '📜 قوانین فروشگاه', 'callback_data' => 'show_store_rules'], ['text' => '🛍️ سفارشات من', 'callback_data' => 'my_orders']],
            [['text' => '🔍 جستجوی محصول', 'callback_data' => 'activate_inline_search']],
            [['text' => 'ℹ️ درباره ما', 'callback_data' => 'show_about_us'], ['text' => '📞 پشتیبانی', 'callback_data' => 'contact_support']],
        ];
        $buttons = array_merge($buttons, $staticButtons);

        if (!empty($channelId)) {
            $channelUsername = str_replace('@', '', (string)$channelId);
            $buttons[] = [['text' => '📢 عضویت در کانال فروشگاه', 'url' => "https://t.me/{$channelUsername}"]];
        }
        if (!empty($user) && !empty($user['is_admin'])) {
            $buttons[] = [['text' => '⚙️ مدیریت فروشگاه', 'callback_data' => 'admin_panel_entry']];
        }

        $keyboard = ['inline_keyboard' => $buttons];

        $data = [
            'chat_id'      => $this->chatId,
            'text'         => $menuText,
            'reply_markup' => $keyboard,
            'parse_mode'   => 'HTML',
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest('editMessageText', $data);
        } else {
            $this->sendRequest('sendMessage', $data);
        }
    }


    private function translateInvoiceStatus(string $status): string
    {
        return match ($status) {
            'pending_payment' => '⏳ در انتظار پرداخت',
            'payment_review' => '🔎 در حال بررسی پرداخت',
            'approved' => '✅ تایید شده (آماده‌سازی برای ارسال)',
            'rejected' => '❌ رد شده',
            default => 'نامشخص',
        };
    }


    private function generateInvoiceCardText(array $invoice): string
    {
        $invoiceId = $invoice['id'];
        $date = jdf::jdate('Y/m/d H:i', strtotime($invoice['created_at']));
        $totalAmount = number_format($invoice['total_amount']);
        $status = $this->translateInvoiceStatus($invoice['status']);

        $text = "📄 <b>سفارش شماره:</b> <code>{$invoiceId}</code>\n";
        $text .= "📅 <b>تاریخ ثبت:</b> {$date}\n";
        $text .= "💰 <b>مبلغ کل:</b> {$totalAmount} تومان\n";
        $text .= "📊 <b>وضعیت:</b> {$status}";

        return $text;
    }


    public function showMyOrdersList($page = 1, $messageId = null): void
    {
        $allInvoices = DB::table('invoices')->find(['user_id' => $this->chatId]);

        if (empty($allInvoices)) {
            $this->Alert("شما تاکنون هیچ سفارشی ثبت نکرده‌اید.");
            return;
        }

        usort($allInvoices, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

        $perPage = 5;
        $totalPages = ceil(count($allInvoices) / $perPage);
        $offset = ($page - 1) * $perPage;
        $invoicesOnPage = array_slice($allInvoices, $offset, $perPage);
        $newMessageIds = [];
        $text = "لیست سفارشات شما (صفحه {$page} از {$totalPages}):";
        if ($messageId) {
            $res = $this->sendRequest("editMessageText", ['chat_id' => $this->chatId, 'message_id' => $messageId, 'text' => $text, 'reply_markup' => ['inline_keyboard' => []]]);
            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        } else {
            $user = DB::table('users')->findById($this->chatId);
            if (!empty($user['message_ids']))
                $this->deleteMessages($user['message_ids']);
        }



        foreach ($invoicesOnPage as $invoice) {
            $invoiceText = $this->generateInvoiceCardText($invoice);
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔍 نمایش جزئیات کامل', 'callback_data' => 'show_order_details_' . $invoice['id']]]
                ]
            ];

            if ($invoice['status'] === 'pending_payment') {
                $keyboard['inline_keyboard'][] = [['text' => '📸 ارسال رسید پرداخت', 'callback_data' => 'upload_receipt_' . $invoice['id']]];
            }

            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $invoiceText,
                "parse_mode" => "HTML",
                "reply_markup" => $keyboard
            ]);
            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "my_orders_page_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "my_orders_page_" . ($page + 1)];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];

        $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => "--- صفحه {$page} از {$totalPages} ---",
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);


        DB::table('users')->update($this->chatId, ['message_ids' => $newMessageIds]);
    }


    public function showSingleOrderDetails(string $invoiceId, int $messageId): void
    {
        $invoice = DB::table('invoices')->findById($invoiceId);
        if (!$invoice || $invoice['user_id'] != $this->chatId) {
            $this->Alert("خطا: این سفارش یافت نشد یا متعلق به شما نیست.");
            return;
        }

        $settings = DB::table('settings')->all();
        $storeName = $settings['store_name'] ?? 'فروشگاه ما';
        $date = jdf::jdate('Y/m/d H:i', strtotime($invoice['created_at']));
        $status = $this->translateInvoiceStatus($invoice['status']);
        $text = "🧾 <b>{$storeName}</b>\n\n";
        $text .= "🆔 <b>شماره فاکتور:</b> <code>{$invoiceId}</code>\n";
        $text .= "📆 <b>تاریخ ثبت:</b> {$date}\n";
        $text .= "📊 <b>وضعیت فعلی:</b> {$status}\n\n";

        $text .= "🚚 <b>مشخصات گیرنده:</b>\n";
        $text .= "👤 <b>نام:</b> {$invoice['user_info']['name']}\n";
        $text .= "📞 <b>تلفن:</b> <code>{$invoice['user_info']['phone']}</code>\n";
        $text .= "📍 <b>آدرس:</b> {$invoice['user_info']['address']}\n\n";

        $text .= "📋 <b>لیست اقلام خریداری شده:</b>\n";
        $totalPrice = 0;
        foreach ($invoice['products'] as $product) {
            $unitPrice = $product['price'];
            $itemPrice = $unitPrice * $product['quantity'];
            $totalPrice += $itemPrice;

            $text .= "🔸 <b>{$product['name']}</b>\n";
            $text .= "  ➤ تعداد: {$product['quantity']} | قیمت واحد: " . number_format($unitPrice) . " تومان\n";
        }
        $text .= "\n💰 <b>مبلغ نهایی پرداخت شده:</b> <b>" . number_format($invoice['total_amount']) . " تومان</b>";

        $keyboard = [['text' => '⬅️ بازگشت   ', 'callback_data' => 'show_order_summary_' . $invoiceId]];

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            "message_id" => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [$keyboard]]
        ]);
    }

    public function showOrderSummaryCard(string $invoiceId, int $messageId): void
    {
        $invoice = DB::table('invoices')->findById($invoiceId);
        if (!$invoice || $invoice['user_id'] != $this->chatId) {
            $this->Alert("خطا: سفارش یافت نشد.");
            return;
        }

        $invoiceText = $this->generateInvoiceCardText($invoice);
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔍 نمایش جزئیات کامل', 'callback_data' => 'show_order_details_' . $invoice['id']]]
            ]
        ];

        if ($invoice['status'] === 'pending_payment') {
            $keyboard['inline_keyboard'][] = [['text' => '📸 ارسال رسید پرداخت', 'callback_data' => 'upload_receipt_' . $invoice['id']]];
        }


        $this->sendRequest("editMessageText", [
            "chat_id" => $this->chatId,
            "message_id" => $messageId,
            "text" => $invoiceText,
            "parse_mode" => "HTML",
            "reply_markup" => $keyboard
        ]);
    }
    public function showFavoritesList($page = 1, $messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }
        $cart = json_decode($user['cart'] ?? '{}', true);
        $favoritesIds = json_decode($user['favorites'] ?? '[]', true);
        if (empty($favoritesIds)) {
            $this->Alert("❤️ لیست علاقه‌مندی‌های شما خالی است.");
            return;
        }


        $allProducts = DB::table('products')->all();
        $favoriteProducts = array_filter($allProducts, fn($product) => in_array($product['id'], $favoritesIds));

        $perPage = 5;
        $totalPages = ceil(count($favoriteProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($favoriteProducts, $offset, $perPage);

        $newMessageIds = [];

        foreach ($productsOnPage as $product) {
            $productText = $this->generateProductCardText($product);
            $productId = $product['id'];
            $keyboardRows = [];

            $keyboardRows[] = [['text' => '❤️ حذف از علاقه‌مندی', 'callback_data' => 'toggle_favorite_' . $productId]];

            if (isset($cart[$productId])) {
                $quantity = $cart[$productId];
                $keyboardRows[] = [
                    ['text' => '➕', 'callback_data' => "cart_increase_{$productId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => "manual_quantity_{$productId}"],
                    ['text' => '➖', 'callback_data' => "cart_decrease_{$productId}"]
                ];
            } else {
                $keyboardRows[] = [['text' => '🛒 افزودن به سبد خرید', 'callback_data' => 'add_to_cart_' . $productId]];
            }

            $productKeyboard = ['inline_keyboard' => $keyboardRows];
            if (!empty($product['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $product['image_file_id'],
                    "caption" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            }
            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $navText = "--- علاقه‌مندی‌ها (صفحه {$page} از {$totalPages}) ---";
        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "fav_list_page_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "fav_list_page_" . ($page + 1)];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];

        $navMessageRes = $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $navText,
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);
        if (isset($navMessageRes['result']['message_id'])) {
            $newMessageIds[] = $navMessageRes['result']['message_id'];
        }

        DB::table('users')->update($this->chatId, ['message_ids' => $newMessageIds]);
    }


    public function showCart($messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);

        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }
        if (empty($cart)) {
            $this->Alert("🛒 سبد خرید شما خالی است.");
            return;
        }

        $settings = DB::table('settings')->all();
        $shippingInfoComplete = !empty($user['shipping_name']) && !empty($user['shipping_phone']) && !empty($user['shipping_address']);

        $storeName = $settings['store_name'] ?? 'فروشگاه من';
        $deliveryCost = (int) ($settings['delivery_price'] ?? 0);
        $taxPercent = (int) ($settings['tax_percent'] ?? 0);
        $discountFixed = (int) ($settings['discount_fixed'] ?? 0);

        $date = jdf::jdate('Y/m/d');
        $invoiceId = $this->chatId;

        $text = "🧾 <b>فاکتور خرید از {$storeName}</b>\n";
        $text .= "📆 تاریخ: {$date}\n";
        $text .= "🆔 شماره فاکتور: {$invoiceId}\n\n";

        if ($shippingInfoComplete) {
            $text .= "🚚 <b>مشخصات گیرنده:</b>\n";
            $text .= "👤 نام: {$user['shipping_name']}\n";
            $text .= "📞 تلفن: {$user['shipping_phone']}\n";
            $text .= "📍 آدرس: {$user['shipping_address']}\n\n";
        }

        $text .= "<b>📋 لیست اقلام:</b>\n";
        $allProducts = DB::table('products')->all();
        $totalPrice = 0;

        foreach ($cart as $productId => $quantity) {
            if (isset($allProducts[$productId])) {
                $product = $allProducts[$productId];
                $unitPrice = $product['price'];
                $itemPrice = $unitPrice * $quantity;
                $totalPrice += $itemPrice;

                $text .= "🔸 {$product['name']}\n";
                $text .= "  ➤ تعداد: {$quantity} | قیمت واحد: " . number_format($unitPrice) . " تومان\n";
                $text .= "  💵 مجموع: " . number_format($itemPrice) . " تومان\n\n";
            }
        }

        $taxAmount = round($totalPrice * $taxPercent / 100);
        $grandTotal = $totalPrice + $taxAmount + $deliveryCost - $discountFixed;

        $text .= "📦 هزینه ارسال: " . number_format($deliveryCost) . " تومان\n";
        $text .= "💸 تخفیف: " . number_format($discountFixed) . " تومان\n";
        $text .= "📊 مالیات ({$taxPercent}%): " . number_format($taxAmount) . " تومان\n";
        $text .= "💰 <b>مبلغ نهایی قابل پرداخت:</b> <b>" . number_format($grandTotal) . "</b> تومان";


        $keyboardRows = [];
        if ($shippingInfoComplete) {

            $keyboardRows[] = [['text' => '💳 پرداخت نهایی', 'callback_data' => 'checkout']];
            $keyboardRows[] = [['text' => '🗑 خالی کردن سبد', 'callback_data' => 'clear_cart'], ['text' => '✏️ ویرایش سبد خرید', 'callback_data' => 'edit_cart']];
            $keyboardRows[] = [['text' => '📝 ویرایش اطلاعات ارسال', 'callback_data' => 'edit_shipping_info']];
        } else {
            $keyboardRows[] = [['text' => '📝 تکمیل اطلاعات ارسال', 'callback_data' => 'complete_shipping_info']];
            $keyboardRows[] = [['text' => '🗑 خالی کردن سبد', 'callback_data' => 'clear_cart'], ['text' => '✏️ ویرایش سبد خرید', 'callback_data' => 'edit_cart']];
        }

        $keyboardRows[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];
        $keyboard = ['inline_keyboard' => $keyboardRows];

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                "message_id" => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ]);
        }
    }

    // public function showCart($messageId = null): void
    // {
    //     $user = DB::table('users')->findById($this->chatId);
    //     $cart = json_decode($user['cart'] ?? '{}', true);

    //     if (empty($cart)) {
    //         $this->Alert("🛒 سبد خرید شما خالی است.");
    //         return;
    //     }

    //     $webAppUrl = "https://www.rammehraz.com/Rambot/test/Amir/MTR/mini_app/cart.html";

    //     $text = "🛒 برای مشاهده و مدیریت سبد خرید خود، لطفاً روی دکمه زیر کلیک کنید:";
    //     $keyboard = [
    //         'inline_keyboard' => [
    //             [['text' => '🛍️ مشاهده سبد خرید پیشرفته', 'web_app' => ['url' => $webAppUrl]]],
    //             [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
    //         ]
    //     ];

    //     if ($messageId) {
    //         $this->sendRequest("editMessageText", [
    //             'chat_id' => $this->chatId,
    //             "message_id" => $messageId,
    //             'text' => $text,
    //             'reply_markup' => json_encode($keyboard)
    //         ]);
    //     } else {
    //         $this->sendRequest("sendMessage", [
    //             'chat_id' => $this->chatId,
    //             'text' => $text,
    //             'reply_markup' => json_encode($keyboard)
    //         ]);
    //     }
    // }


    public function showAdminMainMenu($messageId = null): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🛍 مدیریت دسته‌بندی‌ها', 'callback_data' => 'admin_manage_categories']],
                [['text' => '📝 مدیریت محصولات', 'callback_data' => 'admin_manage_products']],
                [['text' => '🧾 مدیریت فاکتورها', 'callback_data' => 'admin_manage_invoices']],
                [['text' => '⚙️ تنظیمات ربات', 'callback_data' => 'admin_bot_settings']],
                [['text' => '📊 آمار و گزارشات', 'callback_data' => 'admin_reports']],
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
            ]
        ];

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => "پنل مدیریت ربات:",
                "reply_markup" => json_encode($keyboard)
            ]);
            return;
        } else {
            $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => "پنل مدیریت ربات:",
                "reply_markup" => $keyboard
            ]);
        }
    }

    public function showInvoiceManagementMenu($messageId = null): void
    {
        $text = "🧾 بخش مدیریت فاکتورها.\n\nلطفاً وضعیت فاکتورهایی که می‌خواهید مشاهده کنید را انتخاب نمایید:";
        $keyboard = [
            'inline_keyboard' => [
                // دکمه برای فاکتورهایی که نیاز به بررسی دارند (مهم‌ترین)
                [['text' => '🔎 فاکتورهای در حال بررسی', 'callback_data' => 'admin_list_invoices_payment_review_page_1']],
                // سایر وضعیت‌ها
                [['text' => '✅ تایید شده', 'callback_data' => 'admin_list_invoices_approved_page_1'], ['text' => '❌ رد شده', 'callback_data' => 'admin_list_invoices_rejected_page_1']],
                [['text' => '⏳ در انتظار پرداخت', 'callback_data' => 'admin_list_invoices_pending_payment_page_1']],
                [['text' => '📜 نمایش همه فاکتورها', 'callback_data' => 'admin_list_invoices_all_page_1']],

                [['text' => '⬅️ بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'reply_markup' => json_encode($keyboard)
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }


    public function showInvoiceListByStatus(string $status, int $page = 1, $messageId = null): void
    {
        if ($status === 'all') {
            $allInvoices = array_values(DB::table('invoices')->all());
            $statusText = 'همه فاکتورها';
        } else {
            $allInvoices = array_values(array: DB::table('invoices')->find(['status' => $status]));
            $statusText = $this->translateInvoiceStatus($status);
        }
        if (empty($allInvoices)) {
            $statusText = $this->translateInvoiceStatus($status);
            $this->Alert("هیچ فاکتوری با وضعیت '{$statusText}' یافت نشد.");
            $this->showInvoiceManagementMenu($messageId);
            return;
        }


        usort($allInvoices, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

        $perPage = 5;
        $totalPages = ceil(count($allInvoices) / $perPage);
        $offset = ($page - 1) * $perPage;
        $invoicesOnPage = array_slice($allInvoices, $offset, $perPage);

        $statusText = $this->translateInvoiceStatus($status);
        $text = "لیست فاکتورهای <b>{$statusText}</b> (صفحه {$page} از {$totalPages}):";

        $user = DB::table('users')->findById($this->chatId);
        if (!empty($user['message_ids']))
            $this->deleteMessages($user['message_ids']);
        $res = $this->sendRequest("sendMessage", ['chat_id' => $this->chatId, 'text' => $text, 'parse_mode' => 'HTML']);
        $newMessageIds = [$res['result']['message_id'] ?? null];

        foreach ($invoicesOnPage as $invoice) {
            $cardText = "📄 <b>فاکتور:</b> <code>{$invoice['id']}</code>\n";
            $cardText .= "👤 <b>کاربر:</b> {$invoice['user_info']['name']} (<code>{$invoice['user_id']}</code>)\n";
            $cardText .= "💰 <b>مبلغ:</b> " . number_format($invoice['total_amount']) . " تومان\n";
            $cardText .= "📅 <b>تاریخ:</b> " . jdf::jdate('Y/m/d H:i', strtotime($invoice['created_at']));


            $keyboard = [['text' => '👁 مشاهده جزئیات', 'callback_data' => "admin_view_invoice:{$invoice['id']}:{$status}:{$page}"]];

            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $cardText,
                "parse_mode" => "HTML",
                "reply_markup" => ['inline_keyboard' => [$keyboard]]
            ]);
            if (isset($res['result']['message_id']))
                $newMessageIds[] = $res['result']['message_id'];
        }

        $navButtons = [];
        if ($page > 1)
            $navButtons[] = ['text' => "▶️ قبل", 'callback_data' => "admin_list_invoices_{$status}_page_" . ($page - 1)];
        if ($page < $totalPages)
            $navButtons[] = ['text' => "بعد ◀️", 'callback_data' => "admin_list_invoices_{$status}_page_" . ($page + 1)];

        $navKeyboard = [];
        if (!empty($navButtons))
            $navKeyboard[] = $navButtons;
        $navKeyboard[] = [['text' => '⬅️ بازگشت به منوی فاکتورها', 'callback_data' => 'admin_manage_invoices']];

        $navMessageRes = $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => "--- صفحه {$page} ---",
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);
        if (isset($navMessageRes['result']['message_id']))
            $newMessageIds[] = $navMessageRes['result']['message_id'];

        DB::table('users')->update($this->chatId, ['message_ids' => array_filter($newMessageIds)]);
    }

    public function showAdminInvoiceDetails(string $invoiceId, string $fromStatus, int $fromPage, int $messageId): void
    {
        $invoice = DB::table('invoices')->findById($invoiceId);
        if (!$invoice) {
            $this->Alert("خطا: فاکتور یافت نشد.");
            return;
        }

        $text = $this->notifyAdminOfNewReceipt($invoiceId, null, false);

        $keyboard = [];
        if ($invoice['status'] === 'payment_review') {
            $keyboard[] = [
                ['text' => '✅ تایید فاکتور', 'callback_data' => 'admin_approve_' . $invoiceId],
                ['text' => '❌ رد فاکتور', 'callback_data' => 'admin_reject_' . $invoiceId]
            ];
        }
        $keyboard[] = [['text' => '⬅️ بازگشت به لیست', 'callback_data' => "admin_list_invoices:{$fromStatus}:page:{$fromPage}"]];

        $this->deleteMessage($messageId);

        $requestData = [
            'chat_id' => $this->chatId,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ];

        if (!empty($invoice['receipt_file_id'])) {
            $requestData['photo'] = $invoice['receipt_file_id'];
            $requestData['caption'] = $text;
            $this->sendRequest("sendPhoto", $requestData);
        } else {
            $requestData['text'] = $text;
            $this->sendRequest("sendMessage", $requestData);
        }
    }
    public function showCategoryList($messageId = null): void
    {
        $this->Alert("در حال ارسال لیست دسته‌بندی‌ها...", false);

        $allCategories = DB::table('categories')->all();

        if (empty($allCategories)) {
            $this->Alert("هیچ دسته‌بندی‌ای وجود ندارد.");
            return;
        }
        $messageId = $this->getMessageId($this->chatId);
        $res = $this->sendRequest("editMessageText", [
            "chat_id" => $this->chatId,
            "message_id" => $messageId,
            "text" => "⏳ در حال ارسال لیست دسته‌بندی‌ها...",
            "reply_markup" => ['inline_keyboard' => []]
        ]);
        $messageIds = [];
        if (isset($res['result']['message_id'])) {
            $messageIds[] = $res['result']['message_id'];
        }
        foreach ($allCategories as $category) {
            $categoryId = $category['id'];
            $categoryName = $category['name'];

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_category_' . $categoryId],
                        ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_category_' . $categoryId]
                    ]
                ]
            ];

            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => "دسته: {$categoryName}",
                "parse_mode" => "HTML",
                "reply_markup" => $keyboard
            ]);
            if (isset($res['result']['message_id'])) {
                $messageIds[] = $res['result']['message_id'];
            }
        }


        $this->sendRequest("sendMessage", [
            "chat_id" => $this->chatId,
            "text" => "--- پایان لیست ---",
            "reply_markup" => [
                'inline_keyboard' => [
                    [['text' => '⬅️ بازگشت ', 'callback_data' => 'admin_manage_categories']]
                ]
            ]
        ]);

        DB::table('users')->update($this->chatId, ['messages_ids' => $messageIds]);
    }
    public function showCategoryManagementMenu($messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        if (isset($user['messages_ids'])) {
            $this->deleteMessages($user['messages_ids']);
        }
        $text = "بخش مدیریت دسته‌بندی‌ها. لطفاً یک گزینه را انتخاب کنید:";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ افزودن دسته‌بندی جدید', 'callback_data' => 'admin_add_category']],
                [['text' => '📜 لیست دسته‌بندی‌ها', 'callback_data' => 'admin_category_list']],
                [['text' => '⬅️ بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']]
            ]
        ];

        if ($messageId) {
            $res = $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => $text,
                "reply_markup" => json_encode($keyboard)
            ]);
        } else {
            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $text,
                "reply_markup" => json_encode($keyboard)
            ]);
        }
        if (isset($res['result']['message_id'])) {
            $this->saveMessageId($this->chatId, $res['result']['message_id']);
        }
    }
    public function Alert($message, $alert = true): void
    {
        if ($this->callbackQueryId) {
            $data = [
                'callback_query_id' => $this->callbackQueryId,
                'text' => $message,
                'show_alert' => $alert
            ];
            $this->sendRequest("answerCallbackQuery", $data);
        } else {
            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $message,
            ]);
            $this->deleteMessage($res['result']['message_id'] ?? null, 3);
        }
    }
    public function handlePreCheckoutQuery($update): void
    {
        if (isset($update['pre_checkout_query'])) {
            $query_id = $update['pre_checkout_query']['id'];

            // به تلگرام اطلاع می‌دهیم که پرداخت معتبر است
            $this->sendRequest("answerPreCheckoutQuery", [
                'pre_checkout_query_id' => $query_id,
                'ok' => true
            ]);
        }
    }
    public function handleSuccessfulPayment($update): void
    {
        if (isset($update['message']['successful_payment'])) {
            $chatId = $update['message']['chat']['id'];
            $payload = $update['message']['successful_payment']['invoice_payload'];

            // ... در اینجا منطق بعد از پرداخت موفق را پیاده‌سازی کنید ...
            // مثلا افزایش موجودی کاربر در دیتابیس
            // DB::table('users')->update($chatId, ['balance' => new_balance]);

            $this->sendRequest("sendMessage", ["chat_id" => $chatId, "text" => "پرداخت شما با موفقیت انجام شد. سپاسگزاریم!"]);
        }
    }
    private function saveOrUpdateUser(array $userFromTelegram): void
    {
        $chatId = $userFromTelegram['id'];

        $existingUser = DB::table('users')->findById($chatId);


        $userData = [
            'first_name' => $userFromTelegram['first_name'],
            'last_name' => $userFromTelegram['last_name'] ?? null,
            'username' => $userFromTelegram['username'] ?? null,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($existingUser === null) {
            $userData['id'] = $chatId;
            $userData['language_code'] = $userFromTelegram['language_code'] ?? 'fa';
            $userData['created_at'] = date('Y-m-d H:i:s');
            $userData['status'] = 'active';
            $userData['is_admin'] = false;
            DB::table('users')->insert($userData);
        } else {
            DB::table('users')->update($chatId, $userData);
        }
    }
    public function createNewCategory(string $name)
    {
        $categories = DB::table('categories')->all();

        $newId = 1;
        $newSortOrder = 0;
        if (!empty($categories)) {
            $ids = array_keys($categories);
            $sortOrders = array_column($categories, 'sort_order');
            $newId = max($ids) + 1;
            $newSortOrder = max($sortOrders) + 1;
        }

        $newCategory = [
            'id' => $newId,
            'name' => $name,
            'parent_id' => 0,
            'is_active' => true,
            'sort_order' => $newSortOrder
        ];

        $res = DB::table('categories')->insert($newCategory);
        if ($res) {
            return $res;
        } else {
            return null;
        }
    }


    public function sendRequest($method, $data)
    {
        $url = "https://api.telegram.org/bot" . $this->botToken . "/$method";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $responseJson = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        $response = json_decode($responseJson, true);

        if ($curlError) {
            return ['ok' => false, 'error_code' => -1, 'description' => $curlError];
        }

        if ($httpCode >= 300) {
            return $response ?: ['ok' => false, 'error_code' => $httpCode, 'description' => 'Unknown HTTP error'];
        }

        return $response;
    }

    public function saveMessageId($chatId, $messageId)
    {
        if (!$chatId || !$messageId) {
            Log::error('saveMessageId failed - Chat ID or Message ID is missing', [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
            ]);
            return false;
        }

        $data = [
            'message_id' => $messageId,
        ];

        $result = DB::table('users')->update($chatId, $data);
        if ($result) {
            return true;
        } else {
            Log::error('saveMessageId failed - Failed to save Message ID', [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
            ]);
            return false;
        }
    }
    public function getMessageId($chatId)
    {
        if (!$chatId) {
            return null;
        }

        $message = DB::table('users')->findById($chatId);
        if ($message && isset($message['message_id'])) {
            return $message['message_id'];
        } else {
            Log::error('getMessageId failed - Message ID not found for Chat ID', [
                'chat_id' => $chatId,
            ]);
            return null;
        }
    }

    public function showProductManagementMenu($messageId = null): void
    {
        $text = "بخش مدیریت محصولات. لطفاً یک گزینه را انتخاب کنید:";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ افزودن محصول جدید', 'callback_data' => 'admin_add_product']],
                [['text' => '📜 لیست محصولات', 'callback_data' => 'admin_product_list']],
                [['text' => '⬅️ بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']]
            ]
        ];

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => $keyboard
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'reply_markup' => $keyboard
            ]);
        }
    }

    public function showProductListByCategory($categoryId, $page = 1, $messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }

        $perPage = 5;
        $allProducts = DB::table('products')->find(['category_id' => $categoryId]);

        if (empty($allProducts)) {
            $this->Alert("هیچ محصولی در این دسته‌بندی یافت نشد.");
            $this->promptUserForCategorySelection($messageId);
            return;
        }

        $totalPages = ceil(count($allProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($allProducts, $offset, $perPage);

        $newMessageIds = [];

        foreach ($productsOnPage as $product) {
            $productText = $this->generateProductCardText($product);
            $productKeyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page],
                        ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page]
                    ],
                    [
                        ['text' => '📢 انتشار در کانال', 'callback_data' => 'admin_publish_product_' . $product['id']]
                    ]
                ]
            ];
            if (!empty($product['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $product['image_file_id'],
                    "caption" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            }

            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $navText = "--- صفحه {$page} از {$totalPages} ---";
        $navButtons = [];
        if ($page > 1) {
            $prevPage = $page - 1;
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "list_products_cat_{$categoryId}_page_{$prevPage}"];
        }
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "list_products_cat_{$categoryId}_page_{$nextPage}"];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به دسته‌بندی‌ها', 'callback_data' => 'admin_product_list']];

        $navMessageRes = $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $navText,
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);
        if (isset($navMessageRes['result']['message_id'])) {
            $newMessageIds[] = $navMessageRes['result']['message_id'];
        }

        DB::table('users')->update($this->chatId, ['message_ids' => $newMessageIds]);
    }
    public function promptForProductCategory($messageId = null): void
    {
        $allCategories = DB::table('categories')->all();

        if (empty($allCategories)) {
            $this->Alert(message: "ابتدا باید حداقل یک دسته‌بندی ایجاد کنید!");
            $this->showProductManagementMenu($messageId);
            return;
        }

        $categoryButtons = [];
        $row = [];
        foreach ($allCategories as $category) {
            $row[] = ['text' => $category['name'], 'callback_data' => 'product_cat_select_' . $category['id']];
            if (count($row) == 2) {
                $categoryButtons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $categoryButtons[] = $row;
        }
        $categoryButtons[] = [['text' => '❌ انصراف و بازگشت', 'callback_data' => 'admin_manage_products']];

        $keyboard = ['inline_keyboard' => $categoryButtons];

        $summaryText = $this->generateCreationSummaryText([]);
        $promptText = "▶️ برای شروع، لطفاً <b>دسته‌بندی</b> محصول را انتخاب کنید:";
        $finalText = $summaryText . $promptText;


        DB::table('users')->update($this->chatId, [
            'state' => 'adding_product_category',
            'state_data' => json_encode([])
        ]);

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $finalText,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $finalText,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        }
    }

    private function generateCreationSummaryText(array $stateData): string
    {
        $text = "<b>📝 در حال افزودن محصول جدید...</b>\n";

        $categoryLabel = isset($stateData['category_name']) ? "✅ دسته‌بندی:" : "▫️ دسته‌بندی:";
        $categoryValue = isset($stateData['category_name'])
            ? htmlspecialchars($stateData['category_name'])
            : "<i>(هنوز انتخاب نشده)</i>";
        $text .= "<b>{$categoryLabel}</b> {$categoryValue}\n";

        // نام محصول
        $nameLabel = isset($stateData['name']) ? "✅ نام:" : "▫️ نام:";
        $nameValue = isset($stateData['name'])
            ? htmlspecialchars($stateData['name'])
            : "<i>(در انتظار ورود...)</i>";
        $text .= "<b>{$nameLabel}</b> {$nameValue}\n";

        // توضیحات محصول
        $descriptionLabel = isset($stateData['description']) ? "✅ توضیحات:" : "▫️ توضیحات:";
        $descriptionValue = isset($stateData['description'])
            ? htmlspecialchars($stateData['description'])
            : "<i>(در انتظار ورود...)</i>";
        $text .= "<b>{$descriptionLabel}</b> {$descriptionValue}\n";

        // تعداد موجودی
        $countLabel = isset($stateData['count']) ? "✅ موجودی:" : "▫️ موجودی:";
        $countValue = isset($stateData['count'])
            ? $stateData['count']
            : "<i>(در انتظار ورود...)</i>";
        $text .= "<b>{$countLabel}</b> {$countValue}\n";

        // قیمت
        $priceLabel = isset($stateData['price']) ? "✅ قیمت:" : "▫️ قیمت:";
        $priceValue = isset($stateData['price'])
            ? number_format($stateData['price']) . " تومان"
            : "<i>(در انتظار ورود...)</i>";
        $text .= "<b>{$priceLabel}</b> {$priceValue}\n\n";



        return $text;
    }
    private function handleProductCreationSteps(): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $state = $user['state'] ?? null;
        $stateData = json_decode($user['state_data'] ?? '{}', true);
        $messageId = $this->getMessageId($this->chatId);

        switch ($state) {
            case 'adding_product_name':
                $productName = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($productName)) {
                    $this->Alert("⚠️ نام محصول نمی‌تواند خالی باشد.");
                    return;
                }
                if (mb_strlen($productName) > 60) {
                    $this->Alert("⚠️ نام محصول طولانی است. (حداکثر ۶۰ کاراکتر)");
                    return;
                }

                $stateData['name'] = $productName;
                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_description',
                    'state_data' => json_encode($stateData)
                ]);

                $summaryText = $this->generateCreationSummaryText($stateData);
                $promptText = "▶️ حالا لطفاً <b>توضیحات</b> محصول را وارد کنید:";

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $summaryText . $promptText,
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_name'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_description':
                $description = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (mb_strlen($description) > 800) {
                    $this->Alert("⚠️ توضیحات محصول طولانی است. (حداکثر ۸۰۰ کاراکتر)");
                    return;
                }

                $stateData['description'] = $description;
                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_count',
                    'state_data' => json_encode($stateData)
                ]);

                $summaryText = $this->generateCreationSummaryText($stateData);
                $promptText = "▶️ حالا لطفاً <b>تعداد موجودی</b> محصول را وارد کنید (فقط عدد):";

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $summaryText . $promptText,
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_description'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_count':
                $count = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (!is_numeric($count) || $count < 0) {
                    $this->Alert("⚠️ لطفاً یک تعداد معتبر وارد کنید.");
                    return;
                }

                $stateData['count'] = (int) $count;
                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_price',
                    'state_data' => json_encode($stateData)
                ]);

                $summaryText = $this->generateCreationSummaryText($stateData);
                $promptText = "▶️ حالا لطفاً <b>قیمت</b> محصول را وارد کنید (به تومان و فقط عدد):";

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $summaryText . $promptText,
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_count'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_price':
                $price = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (!is_numeric($price) || $price < 0) {
                    $this->Alert("⚠️ لطفاً یک قیمت معتبر وارد کنید.");
                    return;
                }

                $stateData['price'] = (int) $price;
                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_photo',
                    'state_data' => json_encode($stateData)
                ]);

                $summaryText = $this->generateCreationSummaryText($stateData);
                $promptText = "▶️ عالی! به عنوان مرحله آخر، <b>عکس</b> محصول را ارسال کنید:";

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $summaryText . $promptText,
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_price'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_photo':
                $this->deleteMessage($this->messageId);

                if (!isset($this->message['photo'])) {
                    $this->Alert("⚠️ لطفاً فقط عکس محصول را ارسال کنید. امکان ثبت محصول بدون عکس وجود ندارد.");
                    return;
                }

                $stateData['image_file_id'] = end($this->message['photo'])['file_id'];
                DB::table('users')->update($this->chatId, [
                    'state' => 'adding_product_confirmation',
                    'state_data' => json_encode($stateData)
                ]);
                $this->deleteMessage($messageId);
                $this->showConfirmationPreview();
                break;
        }
    }
    private function generateProductCardText(array $product): string
    {

        $rtl_on = "\u{202B}";
        $rtl_off = "\u{202C}";

        $name = $product['name'];
        $desc = $product['description'] ?? 'توضیحی ثبت نشده';
        $price = number_format($product['price']);

        $text = $rtl_on;
        $text .= "🛍️ <b>{$name}</b>\n\n";
        $text .= "{$desc}\n\n";

        $count = (int) ($product['count'] ?? 0);
        $text .= "📦 <b>موجودی:</b> {$count} عدد\n";

        if (isset($product['quantity'])) {

            $quantity = (int) $product['quantity'];
            $text .= "🔢 <b>تعداد در سبد:</b> {$quantity} عدد\n";
        }
        $text .= "💵 <b>قیمت:</b> {$price} تومان";
        $text .= $rtl_off;

        return $text;
    }


    public function promptUserForCategorySelection($messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }
        $allCategories = DB::table('categories')->all();
        if (empty($allCategories)) {
            $this->Alert("هیچ دسته‌بندی‌ای برای نمایش محصولات وجود ندارد!");
            $this->showProductManagementMenu(messageId: $messageId);
            return;
        }

        $categoryButtons = [];
        $row = [];
        foreach ($allCategories as $category) {
            $row[] = ['text' => $category['name'], 'callback_data' => 'list_products_cat_' . $category['id'] . '_page_1'];
            if (count($row) >= 2) {
                $categoryButtons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $categoryButtons[] = $row;
        }

        $categoryButtons[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'admin_manage_products']];

        $keyboard = ['inline_keyboard' => $categoryButtons];
        $text = "لطفاً برای مشاهده محصولات، یک دسته‌بندی را انتخاب کنید:";

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $text,
            'reply_markup' => $keyboard
        ]);
    }
    private function showConfirmationPreview(): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $stateData = json_decode($user['state_data'] ?? '{}', true);

        // ساخت متن پیش نمایش
        $previewText = " لطفاً اطلاعات زیر را بررسی و تایید کنید:\n\n";
        $previewText .= "📦 نام محصول: " . ($stateData['name'] ?? 'ثبت نشده') . "\n";
        $previewText .= "📝 توضیحات: " . ($stateData['description'] ?? 'ثبت نشده') . "\n";
        $previewText .= "🔢 موجودی: " . ($stateData['count'] ?? '۰') . " عدد\n";
        $previewText .= "💰 قیمت: " . number_format($stateData['price'] ?? 0) . " تومان\n\n";
        $previewText .= "در صورت صحت اطلاعات، دکمه \"تایید و ذخیره\" را بزنید.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید و ذخیره', 'callback_data' => 'product_confirm_save'],
                    ['text' => '❌ لغو عملیات', 'callback_data' => 'product_confirm_cancel']
                ]
            ]
        ];

        if (!empty($stateData['image_file_id'])) {
            $res = $this->sendRequest('sendPhoto', [
                'chat_id' => $this->chatId,
                'photo' => $stateData['image_file_id'],
                'caption' => $previewText,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } else {
            $res = $this->sendRequest('sendMessage', [
                'chat_id' => $this->chatId,
                'text' => $previewText,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        }

        if (isset($res['result']['message_id'])) {
            $this->saveMessageId($this->chatId, $res['result']['message_id']);
        }
    }
    private function handleProductUpdate(string $state): void
    {
        $field = str_replace('editing_product_', '', $state);

        $user = DB::table('users')->findById($this->chatId);
        $stateData = json_decode($user['state_data'] ?? '{}', true);

        $productId = $stateData['product_id'] ?? null;
        $categoryId = $stateData['category_id'] ?? null;
        $page = $stateData['page'] ?? null;
        $messageId = $stateData['message_id'] ?? null;

        if (!$productId || !$categoryId || !$page || !$messageId) {
            $this->Alert("خطا در پردازش ویرایش. لطفاً دوباره تلاش کنید.");
            DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);
            return;
        }

        $this->deleteMessage($this->messageId);

        $updateData = [];
        $value = null;

        switch ($field) {
            case 'name':
                $value = trim($this->text);
                if (empty($value)) {
                    $this->Alert("نام نمی‌تواند خالی باشد.");
                    return;
                }
                $updateData['name'] = $value;
                break;
            case 'description':
                $updateData['description'] = trim($this->text);
                break;
            case 'count':
            case 'price':
                $value = trim($this->text);
                if (!is_numeric($value) || $value < 0) {
                    $this->Alert("مقدار وارد شده باید یک عدد معتبر باشد.");
                    return;
                }
                $updateData[$field] = (int) $value;
                break;
            case 'image_file_id':
                if (isset($this->message['photo'])) {
                    $updateData['image_file_id'] = end($this->message['photo'])['file_id'];
                } else {
                    $this->Alert("لطفاً یک عکس ارسال کنید.");
                    return;
                }
                break;
        }

        DB::table('products')->update($productId, $updateData);

        DB::table('users')->update($this->chatId, ['state' => '', 'state_data' => '']);
        $this->showProductEditMenu($productId, $messageId, $categoryId, $page);
    }

    public function showProductEditMenu(int $productId, int $messageId, int $categoryId, int $page): void
    {
        $product = DB::table('products')->findById($productId);
        if (!$product) {
            $this->Alert("خطا: محصول یافت نشد!");
            $this->deleteMessage($messageId);
            return;
        }

        $category = DB::table('categories')->findById($product['category_id']);
        $categoryName = $category ? $category['name'] : 'تعیین نشده';

        $rtl_on = "\u{202B}";
        $rtl_off = "\u{202C}";
        $name = $product['name'];
        $desc = $product['description'] ?? 'توضیحی ثبت نشده';
        $price = number_format($product['price']);
        $count = (int) ($product['count'] ?? 0);

        $text = $rtl_on;
        $text .= "🛍️ <b>{$name}</b>\n\n";
        $text .= "{$desc}\n\n";
        $text .= "🗂️ <b>دسته‌بندی:</b> {$categoryName}\n";
        $text .= "📦 <b>موجودی:</b> {$count} عدد\n";
        $text .= "💵 <b>قیمت:</b> {$price} تومان";
        $text .= $rtl_off;

        $text .= "\n\n";
        $text .= "⚙️ شما در حال ویرایش این محصول هستید.\n";
        $text .= "کدام بخش را می‌خواهید ویرایش کنید؟";


        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✏️ نام', 'callback_data' => "edit_field_name_{$productId}_{$categoryId}_{$page}"],
                    ['text' => '✏️ توضیحات', 'callback_data' => "edit_field_description_{$productId}_{$categoryId}_{$page}"]
                ],
                [
                    ['text' => '✏️ تعداد', 'callback_data' => "edit_field_count_{$productId}_{$categoryId}_{$page}"],
                    ['text' => '✏️ قیمت', 'callback_data' => "edit_field_price_{$productId}_{$categoryId}_{$page}"]
                ],
                [
                    ['text' => '🖼️ عکس', 'callback_data' => "edit_field_imagefileid_{$productId}_{$categoryId}_{$page}"],
                    ['text' => '🗂️ تغییر دسته‌بندی', 'callback_data' => "edit_field_category_{$productId}_{$categoryId}_{$page}"]
                ],
                [
                    ['text' => '✅ پایان ویرایش و بازگشت', 'callback_data' => "confirm_product_edit_{$productId}_cat_{$categoryId}_page_{$page}"]
                ],
            ]
        ];

        $method = !empty($product['image_file_id']) ? "editMessageCaption" : "editMessageText";
        $textOrCaptionKey = !empty($product['image_file_id']) ? "caption" : "text";

        $this->sendRequest($method, [
            'chat_id'      => $this->chatId,
            'message_id'   => $messageId,
            $textOrCaptionKey => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard
        ]);
    }
    private function createNewProduct(array $productData): void
    {
        $products = DB::table('products')->all();
        $newId = empty($products) ? 1 : max(array_keys($products)) + 1;

        $finalProduct = [
            'id' => $newId,
            'name' => $productData['name'],
            'description' => $productData['description'] ?? '',
            'price' => $productData['price'],
            'category_id' => $productData['category_id'],
            'count' => $productData['count'] ?? 0,
            'image_file_id' => $productData['image_file_id'] ?? null,
            'is_active' => true,
        ];


        $result = DB::table('products')->insert($finalProduct);

        if ($result) {
            $this->notifyChannelOfNewProduct($finalProduct);
        }
    }

    private function generateChannelPostText(array $product, string $categoryName): string
    {
        $name = htmlspecialchars($product['name']);
        $desc = htmlspecialchars($product['description'] ?? 'توضیحی ثبت نشده');
        $price = number_format($product['price']);

        $productNameHashtag = '#' . str_replace(' ', '_', $product['name']);
        $categoryNameHashtag = '#' . str_replace(' ', '_', $categoryName);
        $hashtags = "\n\n" . '#محصول_جدید ' . $productNameHashtag . ' ' . $categoryNameHashtag;

        $text = "🛍 <b>{$name}</b>\n\n";
        $text .= "{$desc}\n\n";
        $text .= "💵 <b>قیمت:</b> {$price} تومان";
        $text .= $hashtags;

        return $text;
    }



    private function notifyChannelOfNewProduct(array $product): bool
    {
        try {
            $settings = DB::table('settings')->all();
            $channelId = $settings['channel_id'] ?? null;

            if (empty($channelId) || !str_starts_with($channelId, '@')) {
                Log::info('notifyChannelOfNewProduct skipped - Channel ID is not defined or invalid in settings.');
                return false;
            }

            $category = DB::table('categories')->findById($product['category_id']);
            $categoryName = $category['name'] ?? 'بدون_دسته';

            $postText = $this->generateChannelPostText($product, $categoryName);

            $productUrl = $this->botLink . 'product_' . $product['id'];

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🛍 خرید محصول', 'url' => $productUrl]]
                ]
            ];

            $data = [
                'chat_id' => $channelId,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ];

            if (!empty($product['image_file_id'])) {
                $data['photo'] = $product['image_file_id'];
                $data['caption'] = $postText;
                $res = $this->sendRequest("sendPhoto", $data);
            } else {
                $data['text'] = $postText;
                $res = $this->sendRequest("sendMessage", $data);
            }

            if (isset($res['ok']) && $res['ok'] === true) {
                if (isset($res['result']['message_id'])) {
                    $channelMessageId = $res['result']['message_id'];
                    DB::table('products')->update($product['id'], ['channel_message_id' => $channelMessageId]);
                }
                return true;
            } else {
                Log::error('Telegram API error while posting to channel.', ['response' => $res]);
                return false;
            }
        } catch (\Throwable $th) {
            Log::error('BotHandler::notifyChannelOfNewProduct - message: ' . $th->getMessage(), [
                'product_id' => $product['id'],
            ]);
            return false;
        }
    }
    public function showUserProductList($categoryId, $page = 1, $messageId = null): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);
        $favorites = json_decode($user['favorites'] ?? '[]', true);

        if (!empty($user['message_ids'])) {
            $this->deleteMessages($user['message_ids']);
        }

        $perPage = 5;
        $allProducts = DB::table('products')->find(['category_id' => $categoryId, 'is_active' => true]);

        if (empty($allProducts)) {
            $this->Alert("متاسفانه محصولی در این دسته‌بندی یافت نشد.");
            return;
        }

        $totalPages = ceil(count($allProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($allProducts, $offset, $perPage);

        $newMessageIds = [];

        foreach ($productsOnPage as $product) {
            $productText = $this->generateProductCardText($product);
            $productId = $product['id'];
            $keyboardRows = [];

            $isFavorite = in_array($productId, $favorites);
            $favoriteButtonText = $isFavorite ? '❤️ حذف از علاقه‌مندی' : '🤍 افزودن به علاقه‌مندی';
            $keyboardRows[] = [['text' => $favoriteButtonText, 'callback_data' => 'toggle_favorite_' . $productId]];

            if (isset($cart[$productId])) {
                $quantity = $cart[$productId];
                $keyboardRows[] = [
                    ['text' => '➕', 'callback_data' => "cart_increase_{$productId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => "manual_quantity_{$productId}"],
                    ['text' => '➖', 'callback_data' => "cart_decrease_{$productId}"]
                ];
            } else {
                $keyboardRows[] = [['text' => '🛒 افزودن به سبد خرید', 'callback_data' => 'add_to_cart_' . $productId]];
            }

            $productKeyboard = ['inline_keyboard' => $keyboardRows];

            if (!empty($product['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $product['image_file_id'],
                    "caption" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            }

            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $navText = "--- صفحه {$page} از {$totalPages} ---";
        $navButtons = [];
        if ($page > 1) {
            $prevPage = $page - 1;
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "user_list_products_cat_{$categoryId}_page_{$prevPage}"];
        }
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "user_list_products_cat_{$categoryId}_page_{$nextPage}"];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];

        $navMessageRes = $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $navText,
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);
        if (isset($navMessageRes['result']['message_id'])) {
            $newMessageIds[] = $navMessageRes['result']['message_id'];
        }

        DB::table('users')->update($this->chatId, ['message_ids' => $newMessageIds]);
    }

    private function refreshProductCard(int $productId, ?int $messageId): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);
        $favorites = json_decode($user['favorites'] ?? '[]', true);

        $product = DB::table('products')->findById($productId);
        if (!$product) {
            if ($messageId) $this->deleteMessage($messageId);
            $this->Alert("خطا: محصول یافت نشد.");
            return;
        }

        if (isset($cart[$productId])) {
            $product['quantity'] = $cart[$productId];
        }
        $newText = $this->generateProductCardText($product);
        $keyboardRows = [];
        $isFavorite = in_array($productId, $favorites);
        $favoriteButtonText = $isFavorite ? '❤️ حذف از علاقه‌مندی' : '🤍 افزودن به علاقه‌مندی';
        $keyboardRows[] = [['text' => $favoriteButtonText, 'callback_data' => 'toggle_favorite_' . $productId]];

        if (isset($cart[$productId])) {
            $quantity = $cart[$productId];
            $keyboardRows[] = [
                ['text' => '➕', 'callback_data' => "cart_increase_{$productId}"],
                ['text' => "{$quantity} عدد", 'callback_data' => "manual_quantity_{$productId}"],
                ['text' => '➖', 'callback_data' => "cart_decrease_{$productId}"]
            ];
        } else {
            $keyboardRows[] = [['text' => '🛒 افزودن به سبد خرید', 'callback_data' => 'add_to_cart_' . $productId]];
        }

        $messageList = $user['message_ids'] ?? [];
        if ($messageId === null || !in_array($messageId, $messageList)) {
            $keyboardRows[] = [['text' => 'منوی اصلی', 'callback_data' => 'main_menu2']];
        }

        $newKeyboard = ['inline_keyboard' => $keyboardRows];

        if ($messageId) {
            $method = !empty($product['image_file_id']) ? "editMessageCaption" : "editMessageText";
            $textOrCaptionKey = !empty($product['image_file_id']) ? "caption" : "text";

            $this->sendRequest($method, [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                $textOrCaptionKey => $newText,
                'parse_mode' => 'HTML',
                'reply_markup' => $newKeyboard
            ]);
        } else {
            if (!empty($product['image_file_id'])) {
                $this->sendRequest("sendPhoto", ["chat_id" => $this->chatId, "photo" => $product['image_file_id'], "caption" => $newText, "parse_mode" => "HTML", "reply_markup" => $newKeyboard]);
            } else {
                $this->sendRequest("sendMessage", ["chat_id" => $this->chatId, "text" => $newText, "parse_mode" => "HTML", "reply_markup" => $newKeyboard]);
            }
        }
    }
    public function activateInlineSearch($messageId = null): void
    {
        $text = "🔍 برای جستجوی محصولات در این چت، روی دکمه زیر کلیک کرده و سپس عبارت مورد نظر خود را تایپ کنید:";
        $buttonText = "شروع جستجو در این چت 🔍";

        if ($messageId == null) {
            $prefilledSearchText = "عبارت جستجو خود را وارد کنید"; // متن درخواستی شما

            $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $text,
                "reply_markup" => json_encode([
                    "inline_keyboard" => [
                        [
                            [
                                "text" => $buttonText,
                                "switch_inline_query_current_chat" => $prefilledSearchText
                            ]
                        ]
                    ]
                ])
            ]);
        } else {
            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                'message_id' => $messageId,
                "text" => $text,
                "reply_markup" => [
                    "inline_keyboard" => [
                        [
                            [
                                "text" => $buttonText,
                                "switch_inline_query_current_chat" => ""
                            ]
                        ],
                        [
                            [
                                "text" => "🔙 بازگشت",
                                "callback_data" => "main_menu"
                            ]
                        ]
                    ]
                ]
            ]);
        }
    }

    public function showSingleProduct(int $productId): void
    {
        $product = DB::table('products')->findById($productId);
        if (!$product) {
            $this->Alert("متاسفانه محصول مورد نظر یافت نشد یا حذف شده است.");
            $this->MainMenu();
            return;
        }


        $this->refreshProductCard($productId, null);
    }

    public function showAboutUs(): void
    {

        $text = "🤖 *درباره توسعه‌دهنده ربات*\n\n";
        $text .= "این ربات یک *نمونه‌کار حرفه‌ای* در زمینه طراحی و توسعه ربات‌های فروشگاهی در تلگرام است که توسط *امیر سلیمانی* طراحی و برنامه‌نویسی شده است.\n\n";
        $text .= "✨ *ویژگی‌های برجسته ربات:*\n";
        $text .= "🔹 پنل مدیریت کامل از داخل تلگرام (افزودن، ویرایش، حذف محصول)\n";
        $text .= "🗂️ مدیریت هوشمند دسته‌بندی محصولات\n";
        $text .= "🛒 سیستم سبد خرید و لیست علاقه‌مندی‌ها\n";
        $text .= "🔍 جستجوی پیشرفته با سرعت بالا (Inline Mode)\n";
        $text .= "💳 اتصال امن به درگاه پرداخت\n\n";
        $text .= "💼 *آیا برای کسب‌وکار خود به یک ربات تلگرامی نیاز دارید؟*\n";
        $text .= "ما آماده‌ایم تا ایده‌های شما را به یک ربات کاربردی و حرفه‌ای تبدیل کنیم.\n\n";
        $text .= "📞 *راه ارتباط با توسعه‌دهنده:* [@Amir_soleimani_79](https://t.me/Amir_soleimani_79)";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⬅️ بازگشت به فروشگاه', 'callback_data' => 'main_menu']]
            ]
        ];

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => false,
            'reply_markup' => json_encode($keyboard)
        ]);
    }



    public function showCartInEditMode($messageId): void
    {
        $this->deleteMessage($messageId);

        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);

        if (empty($cart)) {
            $this->Alert("سبد خرید شما خالی  است.");
            $this->MainMenu();
            return;
        }

        $allProducts = DB::table('products')->all();
        $newMessageIds = [];

        foreach ($cart as $productId => $quantity) {
            if (isset($allProducts[$productId])) {
                $product = $allProducts[$productId];
                $product['quantity'] = $quantity;
                $itemText = $this->generateProductCardText($product);

                $keyboard = [
                    'inline_keyboard' => [
                        [

                            ['text' => '➕', 'callback_data' => "edit_cart_increase_{$productId}"],
                            ['text' => "{$quantity} عدد", 'callback_data' => "manual_quantity_{$productId}_cart"],
                            ['text' => '➖', 'callback_data' => "edit_cart_decrease_{$productId}"]
                        ],
                        [
                            ['text' => '🗑 حذف کامل از سبد', 'callback_data' => "edit_cart_remove_{$productId}"]
                        ]
                    ]
                ];

                if (!empty($product['image_file_id'])) {
                    $res = $this->sendRequest("sendPhoto", [
                        "chat_id" => $this->chatId,
                        "photo" => $product['image_file_id'],
                        "caption" => $itemText,
                        "parse_mode" => "HTML",
                        "reply_markup" => $keyboard
                    ]);
                } else {
                    $res = $this->sendRequest("sendMessage", [
                        "chat_id" => $this->chatId,
                        "text" => $itemText,
                        "parse_mode" => "HTML",
                        "reply_markup" => $keyboard
                    ]);
                }

                if (isset($res['result']['message_id'])) {
                    $newMessageIds[] = $res['result']['message_id'];
                }
            }
        }

        $endEditText = "تغییرات مورد نظر را اعمال کرده و در پایان، دکمه زیر را بزنید:";
        $endEditKeyboard = [['text' => '✅ مشاهده فاکتور نهایی', 'callback_data' => 'show_cart']];

        $this->sendRequest("sendMessage", ['chat_id' => $this->chatId, 'text' => $endEditText, 'reply_markup' => ['inline_keyboard' => [$endEditKeyboard]]]);


        DB::table('users')->update($this->chatId, ['message_ids' => $newMessageIds]);
    }

    private function refreshCartItemCard(int $productId, int $messageId): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);
        $product = DB::table('products')->findById($productId);

        if (!$product) {
            $this->deleteMessage($messageId);
            $this->Alert("خطا: محصول یافت نشد.", false);
            return;
        }

        if (!isset($cart[$productId])) {
            $this->deleteMessage($messageId);
            $this->Alert("محصول از سبد شما حذف شد.", false);
            return;
        }

        $quantity = $cart[$productId];
        $product['quantity'] = $quantity;

        $newText = $this->generateProductCardText($product);
        $newKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '➕', 'callback_data' => "edit_cart_increase_{$productId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => "manual_quantity_{$productId}_cart"],
                    ['text' => '➖', 'callback_data' => "edit_cart_decrease_{$productId}"]
                ],
                [
                    ['text' => '🗑 حذف کامل از سبد', 'callback_data' => "edit_cart_remove_{$productId}"]
                ]
            ]
        ];


        if (!empty($product['image_file_id'])) {

            $this->sendRequest('editMessageCaption', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'caption' => $newText,
                'parse_mode' => 'HTML',
                'reply_markup' => $newKeyboard
            ]);
        } else {
            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $newText,
                'parse_mode' => 'HTML',
                'reply_markup' => $newKeyboard
            ]);
        }
    }

    public function showBotSettingsMenu($messageId = null): void
    {
        $settings = DB::table('settings')->all();

        $storeName = $settings['store_name'] ?? 'تعیین نشده ❌';
        $mainMenuText = $settings['main_menu_text'] ?? 'تعیین نشده ❌';

        $deliveryPrice = number_format($settings['delivery_price'] ?? 0) . ' تومان';
        $taxPercent = ($settings['tax_percent'] ?? 0) . '٪';
        $discountFixed = number_format($settings['discount_fixed'] ?? 0) . ' تومان';

        $cardNumber = $settings['card_number'] ?? 'وارد نشده ❌';
        $cardHolderName = $settings['card_holder_name'] ?? 'وارد نشده ❌';
        $supportId = $settings['support_id'] ?? 'وارد نشده ❌';

        $storeRules = !empty($settings['store_rules']) ? $settings['store_rules'] : '❌ تنظیم نشده';
        $channelId = $settings['channel_id'] ?? 'وارد نشده';


        $text = "⚙️ <b>مدیریت تنظیمات ربات فروشگاه</b>\n\n";
        $text .= "🛒 <b>نام فروشگاه: </b> {$storeName}\n";
        $text .= "🧾 <b>متن منوی اصلی:</b>\n {$mainMenuText}\n\n";

        $text .= "🚚 <b>هزینه ارسال: </b> {$deliveryPrice}\n";
        $text .= "📊 <b>مالیات: </b> {$taxPercent}\n";
        $text .= "🎁 <b>تخفیف ثابت: </b>{$discountFixed}\n\n";

        $text .= "💳 <b>شماره کارت: </b> {$cardNumber}\n";
        $text .= "👤 <b>صاحب حساب: </b> {$cardHolderName}\n";
        $text .= "📢 آیدی کانال: <b>{$channelId}</b>\n";
        $text .= "📞 <b>آیدی پشتیبانی: </b> {$supportId}\n";
        $text .= "📜 <b>قوانین فروشگاه: \n</b> {$storeRules}\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✏️ نام فروشگاه', 'callback_data' => 'edit_setting_store_name'],
                    ['text' => '✏️ متن منو', 'callback_data' => 'edit_setting_main_menu_text']
                ],
                [
                    ['text' => '✏️ هزینه ارسال', 'callback_data' => 'edit_setting_delivery_price'],
                    ['text' => '✏️ درصد مالیات', 'callback_data' => 'edit_setting_tax_percent']
                ],
                [
                    ['text' => '✏️ تخفیف ثابت', 'callback_data' => 'edit_setting_discount_fixed']
                ],
                [
                    ['text' => '✏️ شماره کارت', 'callback_data' => 'edit_setting_card_number'],
                    ['text' => '✏️ نام صاحب حساب', 'callback_data' => 'edit_setting_card_holder_name']
                ],
                [
                    ['text' => '✏️ آیدی پشتیبانی', 'callback_data' => 'edit_setting_support_id'],
                    ['text' => '✏️ آیدی کانال', 'callback_data' => 'edit_setting_channel_id']
                ],
                [
                    ['text' => '✏️ قوانین فروشگاه', 'callback_data' => 'edit_setting_store_rules']
                ],
                [
                    ['text' => '🔙 بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']
                ]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        $res = $messageId
            ? $this->sendRequest("editMessageText", array_merge($data, ['message_id' => $messageId]))
            : $this->sendRequest("sendMessage", $data);

        if (isset($res['result']['message_id'])) {
            $this->saveMessageId($this->chatId, $res['result']['message_id']);
        }
    }
    public function showSupportInfo($messageId = null): void
    {
        $settings = DB::table('settings')->all();
        $supportId = $settings['support_id'] ?? null;

        if (empty($supportId)) {
            $this->Alert("اطلاعات پشتیبانی در حال حاضر تنظیم نشده است.");
            return;
        }

        $username = str_replace('@', '', $supportId);
        $supportUrl = "https://t.me/{$username}";

        $text = "📞 برای ارتباط با واحد پشتیبانی می‌توانید مستقیماً از طریق آیدی زیر اقدام کنید .\n\n";
        $text .= "👤 آیدی پشتیبانی: {$supportId}";

        $keyboard = [
            'inline_keyboard' => [
                // [['text' => '🚀 شروع گفتگو با پشتیبانی', 'url' => $supportUrl]],
                [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }

    public function showStoreRules($messageId = null): void
    {
        $settings = DB::table('settings')->all();
        $rulesText = $settings['store_rules'] ?? 'متاسفانه هنوز قانونی برای فروشگاه تنظیم نشده است.';

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => "<b>📜 قوانین و مقررات فروشگاه</b>\n\n" . $rulesText,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }
    private function handleShippingInfoSteps(): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $state = $user['state'] ?? null;
        $stateData = json_decode($user['state_data'] ?? '{}', true);
        $messageId = $this->getMessageId($this->chatId);

        switch ($state) {
            case 'entering_shipping_name':
                $name = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($name)) {
                    $this->Alert("⚠️ نام و نام خانوادگی نمی‌تواند خالی باشد.");
                    return;
                }
                $stateData['name'] = $name;
                DB::table('users')->update($this->chatId, [
                    'state' => 'entering_shipping_phone',
                    'state_data' => json_encode($stateData)
                ]);
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ نام ثبت شد: {$name}\n\nحالا لطفاً شماره تلفن همراه خود را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'show_cart']]]]
                ]);
                break;

            case 'entering_shipping_phone':
                $phone = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (!is_numeric($phone) || strlen($phone) < 10) {
                    $this->Alert("⚠️ لطفاً یک شماره تلفن معتبر وارد کنید.");
                    return;
                }
                $stateData['phone'] = $phone;
                DB::table('users')->update($this->chatId, [
                    'state' => 'entering_shipping_address',
                    'state_data' => json_encode($stateData)
                ]);
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ شماره تلفن ثبت شد: {$phone}\n\nدر نهایت، لطفاً آدرس دقیق پستی خود را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'show_cart']]]]
                ]);
                break;

            case 'entering_shipping_address':
                $address = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($address)) {
                    $this->Alert("⚠️ آدرس نمی‌تواند خالی باشد.");
                    return;
                }

                // ذخیره نهایی اطلاعات در دیتابیس کاربر
                DB::table('users')->update($this->chatId, [
                    'shipping_name' => $stateData['name'],
                    'shipping_phone' => $stateData['phone'],
                    'shipping_address' => $address,
                    'state' => null, // پاک کردن وضعیت
                    'state_data' => null
                ]);

                $this->deleteMessage($messageId); // حذف پیام راهنما
                $this->Alert("✅ اطلاعات شما با موفقیت ذخیره شد.");
                $this->showCart(); // نمایش مجدد سبد خرید با اطلاعات کامل
                break;
        }
    }
    public function initiateCardPayment($messageId): void
    {
        $user = DB::table('users')->findById($this->chatId);
        $cart = json_decode($user['cart'] ?? '{}', true);

        if (empty($cart)) {
            $this->Alert("سبد خرید شما خالی است!");
            return;
        }

        // ۱. خواندن تنظیمات و اطلاعات کاربر
        $settings = DB::table('settings')->all();
        $cardNumber = $settings['card_number'] ?? null;
        $cardHolderName = $settings['card_holder_name'] ?? null;

        if (empty($cardNumber) || empty($cardHolderName)) {
            $this->Alert("متاسفانه اطلاعات کارت فروشگاه تنظیم نشده است. لطفاً به مدیریت اطلاع دهید.");
            return;
        }

        // ۲. محاسبه مجدد مبلغ نهایی برای ثبت در فاکتور
        $deliveryCost = (int) ($settings['delivery_price'] ?? 0);
        $taxPercent = (int) ($settings['tax_percent'] ?? 0);
        $allProducts = DB::table('products')->all();
        $totalPrice = 0;
        $productsDetails = [];

        foreach ($cart as $productId => $quantity) {
            if (isset($allProducts[$productId])) {
                $product = $allProducts[$productId];
                $itemPrice = $product['price'] * $quantity;
                $totalPrice += $itemPrice;
                $productsDetails[] = [
                    'id' => $productId,
                    'name' => $product['name'],
                    'quantity' => $quantity,
                    'price' => $product['price']
                ];
            }
        }
        $taxAmount = round($totalPrice * $taxPercent / 100);
        $grandTotal = $totalPrice + $taxAmount + $deliveryCost;


        // ۳. ایجاد فاکتور جدید در جدول invoices
        $invoices = DB::table('invoices');
        $newInvoiceId = uniqid('inv_');
        $invoiceData = [
            'id' => $newInvoiceId,
            'user_id' => $this->chatId,
            'user_info' => [
                'name' => $user['shipping_name'],
                'phone' => $user['shipping_phone'],
                'address' => $user['shipping_address']
            ],
            'products' => $productsDetails,
            'total_amount' => $grandTotal,
            'status' => 'pending_payment', // وضعیت: در انتظار پرداخت
            'created_at' => date('Y-m-d H:i:s'),
            'receipt_file_id' => null
        ];
        $invoices->insert($invoiceData);

        // ۴. پاک کردن سبد خرید کاربر
        DB::table('users')->update($this->chatId, ['cart' => '[]']);

        $text = "🧾 <b>رسید ثبت سفارش</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━\n";
        $text .= "🛒 وضعیت سفارش: <b>ثبت شده</b>\n";
        $text .= "💰 مبلغ قابل پرداخت: <b>" . number_format($grandTotal) . " تومان</b>\n";
        $text .= "🕒 زمان ثبت: " . jdf::jdate("Y/m/d - H:i") . "\n";
        $text .= "━━━━━━━━━━━━━━━━━\n\n";

        $text .= "📌 لطفاً مبلغ فوق را به کارت زیر واریز نمایید و سپس از طریق دکمه‌ی زیر، تصویر رسید پرداخت را برای ما ارسال کنید:\n\n";

        $text .= "💳 <b>شماره کارت:</b>\n<code>{$cardNumber}</code>\n";
        $text .= "👤 <b>نام صاحب حساب:</b>\n<b>{$cardHolderName}</b>\n\n";

        $text .= "📦 سفارش شما پس از تأیید پرداخت پردازش و ارسال خواهد شد.\n\n";
        $text .= "در صورت نیاز به راهنمایی، پشتیبانی در دسترس شماست. 🙋‍♂️";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📸 ارسال رسید پرداخت', 'callback_data' => 'upload_receipt_' . $newInvoiceId]],
            ]
        ];

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    public function notifyAdminOfNewReceipt(string $invoiceId, ?string $receiptFileId, bool $send = true): ?string
    {
        $settings = DB::table('settings')->all();
        $adminId = $settings['support_id'] ?? null;
        $invoice = DB::table('invoices')->findById($invoiceId);

        if (!$invoice) {
            Log::error('notifyAdminOfNewReceipt failed - Invoice not found.', [
                'invoice_id' => $invoiceId,
            ]);
            return null;
        }

        $userInfo = $invoice['user_info'];
        $products = $invoice['products'];
        $totalAmount = number_format($invoice['total_amount']);
        $createdAt = jdf::jdate('Y/m/d - H:i', strtotime($invoice['created_at']));

        $text = "🔔 رسید پرداخت جدید دریافت شد 🔔\n\n";
        $text .= "📄 شماره فاکتور: `{$invoiceId}`\n";
        $text .= "📅 تاریخ ثبت: {$createdAt}\n\n";
        $text .= "👤 مشخصات خریدار:\n";
        $text .= "- نام: {$userInfo['name']}\n";
        $text .= "- تلفن: `{$userInfo['phone']}`\n";
        $text .= "- آدرس: {$userInfo['address']}\n\n";
        $text .= "🛍 محصولات خریداری شده:\n";
        foreach ($products as $product) {
            $productPrice = number_format($product['price']);
            $text .= "- {$product['name']} (تعداد: {$product['quantity']}, قیمت واحد: {$productPrice} تومان)\n";
        }
        $text .= "\n";
        $text .= "💰 مبلغ کل پرداخت شده: {$totalAmount} تومان\n\n";
        $text .= "لطفاً رسید را بررسی و وضعیت فاکتور را مشخص نمایید.";

        // اگر send=false باشد، فقط متن را برمی‌گرداند
        if (!$send) {
            return $text;
        }

        if (empty($adminId)) {
            Log::error('notifyAdminOfNewReceipt failed - Admin ID is not set in settings.');
            return null;
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید فاکتور', 'callback_data' => 'admin_approve_' . $invoiceId],
                    ['text' => '❌ رد فاکتور', 'callback_data' => 'admin_reject_' . $invoiceId]
                ]
            ]
        ];

        $this->sendRequest("sendPhoto", ['chat_id' => $adminId, 'photo' => $receiptFileId, 'caption' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]);

        return null;
    }
    /**
     * فرآیند پرداخت را بر اساس داده‌های دریافتی از وب اپ (مینی اپ) آغاز می‌کند.
     * @param array $webData داده‌های سبد خرید که از مینی اپ ارسال شده است.
     */
    public function initiateCardPaymentFromWebApp(array $webData): void
    {
        // ۱. بررسی اولیه داده‌های ورودی از وب اپ
        if (empty($webData['products'])) {
            $this->Alert("خطا: سبد خرید خالی است یا اطلاعات به درستی از اپلیکیشن وب دریافت نشد.");
            return;
        }

        $user = DB::table('users')->findById($this->chatId);
        $settings = DB::table('settings')->all();

        // ۲. بررسی وجود اطلاعات ضروری (اطلاعات کارت فروشگاه و اطلاعات ارسال کاربر)
        $cardNumber = $settings['card_number'] ?? null;
        $cardHolderName = $settings['card_holder_name'] ?? null;
        if (empty($cardNumber) || empty($cardHolderName)) {
            $this->Alert("متاسفانه اطلاعات کارت فروشگاه تنظیم نشده است. لطفاً به مدیریت اطلاع دهید.");
            return;
        }
        // فرض می‌کنیم اطلاعات ارسال قبلاً از کاربر گرفته شده است.
        if (empty($user['shipping_name']) || empty($user['shipping_phone']) || empty($user['shipping_address'])) {
            $this->Alert("اطلاعات ارسال شما کامل نیست. لطفاً ابتدا اطلاعات ارسال خود را تکمیل کنید.");
            $this->MainMenu(); // بازگشت به منوی اصلی
            return;
        }

        // ۳. محاسبه مجدد مبلغ نهایی در سمت سرور (برای امنیت)
        $deliveryCost = (int) ($settings['delivery_price'] ?? 0);
        $taxPercent = (int) ($settings['tax_percent'] ?? 0);
        $allProductsDB = DB::table('products')->all();

        $totalPrice = 0;
        $productsDetailsForInvoice = [];

        foreach ($webData['products'] as $productFromWebApp) {
            $productId = $productFromWebApp['id'];
            $quantity = $productFromWebApp['quantity'];

            // نکته امنیتی: قیمت را از دیتابیس خودمان می‌خوانیم، نه از ورودی کاربر
            if (isset($allProductsDB[$productId])) {
                $productDB = $allProductsDB[$productId];
                $itemPrice = $productDB['price'] * $quantity;
                $totalPrice += $itemPrice;
                $productsDetailsForInvoice[] = [
                    'id' => $productId,
                    'name' => $productDB['name'],
                    'quantity' => $quantity,
                    'price' => $productDB['price']
                ];
            }
        }

        if (empty($productsDetailsForInvoice)) {
            $this->Alert("خطا: محصولات موجود در سبد خرید شما معتبر نیستند.");
            return;
        }

        $taxAmount = round($totalPrice * $taxPercent / 100);
        $grandTotal = $totalPrice + $taxAmount + $deliveryCost;

        // ۴. ایجاد فاکتور جدید در جدول invoices
        $invoices = DB::table('invoices');
        $newInvoiceId = uniqid('inv_');
        $invoiceData = [
            'id' => $newInvoiceId,
            'user_id' => $this->chatId,
            'user_info' => [
                'name' => $user['shipping_name'],
                'phone' => $user['shipping_phone'],
                'address' => $user['shipping_address']
            ],
            'products' => $productsDetailsForInvoice,
            'total_amount' => $grandTotal,
            'status' => 'pending_payment',
            'created_at' => date('Y-m-d H:i:s'),
            'receipt_file_id' => null
        ];
        $invoices->insert($invoiceData);

        // ۵. پاک کردن سبد خرید کاربر در دیتابیس
        DB::table('users')->update($this->chatId, ['cart' => '[]']);

        // ۶. ارسال پیام دستورالعمل پرداخت برای کاربر (به عنوان یک پیام جدید)
        $text = "🧾 <b>رسید ثبت سفارش</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━\n";
        $text .= "🛒 وضعیت سفارش: <b>ثبت شده</b>\n";
        $text .= "💰 مبلغ قابل پرداخت: <b>" . number_format($grandTotal) . " تومان</b>\n";
        $text .= "🕒 زمان ثبت: " . jdf::jdate("Y/m/d - H:i") . "\n";
        $text .= "━━━━━━━━━━━━━━━━━\n\n";

        $text .= "📌 لطفاً مبلغ فوق را به کارت زیر واریز نمایید و سپس از طریق دکمه‌ی زیر، تصویر رسید پرداخت را برای ما ارسال کنید:\n\n";

        $text .= "💳 <b>شماره کارت:</b>\n<code>{$cardNumber}</code>\n";
        $text .= "👤 <b>نام صاحب حساب:</b>\n<b>{$cardHolderName}</b>\n\n";
        $text .= "📦 سفارش شما پس از تأیید پرداخت پردازش و ارسال خواهد شد.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📸 ارسال رسید پرداخت', 'callback_data' => 'upload_receipt_' . $newInvoiceId]],
            ]
        ];

        // از آنجایی که وب اپ بسته شده، پیام جدید ارسال می‌کنیم و پیام قبلی را ویرایش نمی‌کنیم.
        $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    public function mini_app($messageId = null): void
    {
        $webAppUrl = "https://bot.rammehraz.com/MTR/mini_app/test.html";
        $text = "برای باز کردن مینی اپ ساده، روی دکمه زیر کلیک کنید:";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🚀 باز کردن مینی اپ', 'web_app' => ['url' => $webAppUrl]]],
                [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
            ]
        ];
        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                "message_id" => $messageId,
                'text' => $text,
                'reply_markup' => json_encode($keyboard)
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'reply_markup' => json_encode($keyboard)
            ]);
        }
    }
}
