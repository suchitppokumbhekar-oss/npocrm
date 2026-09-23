<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserWorkflowPresentation extends Model
{
    protected $table = "user_workflow_presentations";

    protected $fillable = [
        "user_id",
        "option_type",
        "canonical_key",
        "display_label",
        "is_visible",
        "sort_order",
    ];

    protected $casts = [
        "user_id" => "integer",
        "is_visible" => "boolean",
        "sort_order" => "integer",
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
