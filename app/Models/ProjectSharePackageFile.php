<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectSharePackageFile extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_share_package_id',
        'managed_file_id',
        'version_number',
        'sort_order',
        'created_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function package()
    {
        return $this->belongsTo(ProjectSharePackage::class, 'project_share_package_id');
    }

    public function file()
    {
        return $this->belongsTo(ManagedFile::class, 'managed_file_id');
    }
}
