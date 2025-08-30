<?php

namespace App\Bots\Mtr;

use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;


class MtrService
{
    // --- متدهای مربوط به کاربران ---

    
    public function findOrCreateUser(array $telegramUser): User
    {
        return User::firstOrCreate(
            ['telegram_id' => $telegramUser['id']],
            [
                'first_name' => $telegramUser['first_name'],
                'last_name' => $telegramUser['last_name'] ?? null,
                'username' => $telegramUser['username'] ?? null,
                'name' => trim(($telegramUser['first_name'] ?? '') . ' ' . ($telegramUser['last_name'] ?? '')),
            ]
        );
    }

   
    public function findUserById(int $id): ?User
    {
        return User::find($id);
    }


    // --- متدهای مربوط به محصولات ---

    public function findProductById(int $id): ?Product
    {
        return Product::find($id);
    }


    // --- متدهای مربوط به فاکتورها ---

    public function findInvoiceById(int $id): ?Invoice
    {
        return Invoice::find($id);
    }

 
    public function approveInvoiceAndUpdateStock(int $invoiceId): ?Invoice
    {
        return DB::transaction(function () use ($invoiceId) {
            $invoice = Invoice::find($invoiceId);

            if (!$invoice || $invoice->status === 'approved') {
                return null; // فاکتور قبلا تایید شده یا وجود ندارد
            }

            $purchasedItems = json_decode($invoice->cart_items, true);
            if (is_array($purchasedItems)) {
                foreach ($purchasedItems as $item) {
                    Product::where('id', $item['id'])->decrement('count', $item['quantity']);
                }
            }

            $invoice->status = 'approved';
            $invoice->save();

            return $invoice;
        });
    }


    // --- متدهای مربوط به تنظیمات ---

    
    public function getSetting(string $key, $default = null): ?string
    {
        $setting = Setting::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    
    public function setSetting(string $key, string $value): bool
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        return true;
    }

    // --- متدهای مربوط به دسته‌بندی‌ها ---

    
    public function getActiveCategories()
    {
        return Category::where('is_active', true)->orderBy('sort_order')->get();
    }
}
