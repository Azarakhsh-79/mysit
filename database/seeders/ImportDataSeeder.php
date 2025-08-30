<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ImportDataSeeder extends Seeder
{
    
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        Category::truncate();
        Product::truncate();
        User::truncate();

        $categoriesJson = File::get(base_path('app/Bots/Mtr/data/categories.json'));
        $categories = json_decode($categoriesJson, true);

        foreach ($categories as $category) {
            Category::create([
                'id' => $category['id'],
                'name' => $category['name'],
                'parent_id' => $category['parent_id'],
                'is_active' => $category['is_active'],
                'sort_order' => $category['sort_order'],
            ]);
        }
        $this->command->info('Categories table seeded!');

        $productsJson = File::get(base_path('app/Bots/Mtr/data/products.json'));
        $products = json_decode($productsJson, true);

        foreach ($products as $product) {
            Product::create([
                'id' => $product['id'],
                'name' => $product['name'],
                'description' => $product['description'],
                'price' => $product['price'],
                'category_id' => $product['category_id'],
                'count' => $product['count'],
                'image_file_id' => $product['image_file_id'],
                'is_active' => $product['is_active'],
                'channel_message_id' => $product['channel_message_id'] ?? null,
            ]);
        }
        $this->command->info('Products table seeded!');

        // 3. Import Users
        $usersJson = File::get(base_path('app/Bots/Mtr/data/users.json'));
        $users = json_decode($usersJson, true);

        foreach ($users as $userId => $user) {
            User::create([
                'telegram_id' => $user['id'], 
                'name' => $user['first_name'] . ' ' . ($user['last_name'] ?? ''),
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'username' => $user['username'],
                'language_code' => $user['language_code'] ?? null,
                'created_at' => $user['created_at'] ?? now(),
                'updated_at' => $user['updated_at'] ?? now(),
                'status' => $user['status'] ?? 'active',
                'is_admin' => $user['is_admin'] ?? false,
                'state' => $user['state'] ?? null,
                'state_data' => $user['state_data'] ?? null,
                'message_ids' => isset($user['message_ids']) ? json_encode($user['message_ids']) : null,
                'cart' => $user['cart'] ?? null,
                'favorites' => $user['favorites'] ?? null,
                'shipping_name' => $user['shipping_name'] ?? null,
                'shipping_phone' => $user['shipping_phone'] ?? null,
                'shipping_address' => $user['shipping_address'] ?? null,
            ]);
        }
        $this->command->info('Users table seeded!');

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
}