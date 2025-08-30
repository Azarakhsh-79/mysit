<?php

declare(strict_types=1);

namespace App\Bots\Mtr;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Invoice; 
use App\Models\Setting;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Jsondb
{
    private $model;

    public function __construct(string $table)
    {
        $this->model = match ($table) {
            'users' => new User(),
            'products' => new Product(),
            'categories' => new Category(),
            'invoices' => new Invoice(),
            'settings' => new Setting(),


       
            default => throw new \InvalidArgumentException("Model for table {$table} not found."),
        };
    }

    
    public function get($id)
    {
        if ($this->model instanceof User) {
            return $this->model->where('telegram_id', $id)->first();
        }
        return $this->model->find($id);
    }

   
    public function insert(array $data)
    {
        if ($this->model instanceof User) {
            return $this->model->firstOrCreate(
                ['telegram_id' => $data['id']], 
                $data 
            );
        }
        return $this->model->create($data);
    }

   
    public function update($id, array $data)
    {
        $record = $this->get($id);
        if ($record) {
            return $record->update($data);
        }
        return false;
    }

     public function set(string $key, $value): bool
    {
        if (!$this->model instanceof Setting) {
            throw new \RuntimeException('This method can only be called on the settings table.');
        }

        $this->model->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        return true;
    }
     public function getSetting(string $key, $default = null)
    {
        if (!$this->model instanceof Setting) {
            throw new \RuntimeException('This method can only be called on the settings table.');
        }
        $setting = $this->model->where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

      public function unsetKey($id, string $key)
    {
        $record = $this->get($id);

        if ($record) {
            if (Schema::hasColumn($record->getTable(), $key)) {
                $record->{$key} = null;
                return $record->save();
            }
        }

        return false;
    }
    public function delete($id)
    {
        if ($this->model instanceof User) {
            $record = $this->get($id);
            return $record ? $record->delete() : false;
        }
        return $this->model->destroy($id);
    }

   
    public function query(array $conditions)
    {
        return $this->model->where($conditions)->get();
    }

   
    public function all()
    {
       
        if ($this->model instanceof Product || $this->model instanceof Category) {
            return $this->model->get()->keyBy('id')->toArray();
        }
        return $this->model->all();
    }

    public function save(): void
    {
        // This method was used to write data back to the JSON file.
        // With a database, data is saved instantly on create/update,
        // so this method can be empty.
    }
}