<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "Ready to buy" product category for notes (rayan_crmcategorynote). */
class CrmNoteCategory extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];
}
