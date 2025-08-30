<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

   
    protected $fillable = [
        'name',
        'email',
        'password',
        'telegram_id',
        'first_name',  
        'last_name',   
        'username',    
        'language_code', 
        'status',      
        'is_admin',    
        'state',       
        'state_data',  
        'message_ids', 
        'cart',        
        'favorites',   
        'shipping_name', 
        'shipping_phone',
        'shipping_address',
    ];

    
    protected $hidden = [
        'password',
        'remember_token',
    ];

    
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}