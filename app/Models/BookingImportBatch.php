<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingImportBatch extends Model {
    protected $table='booking_import_batches';
    protected $fillable=['source_type','file_name','status','mapping_json','total_rows','valid_rows','invalid_rows','duplicate_rows','created_by_user_id'];
    protected $casts=['mapping_json'=>'array'];
}
