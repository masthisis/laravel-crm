<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Call outcome (rayan_oncallscategory): 1 no answer, 2 answered, 3 follow up. */
class CrmOncallCategory extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];
}
