<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Department for CRM notes (rayan_crmdepartmentsupport). */
class CrmDepartment extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];
}
