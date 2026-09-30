<?php
namespace App\Models\V8;
use Illuminate\Database\Eloquent\Model;
class MlAcceptanceSource extends Model
{
    protected $table = 'stox_ml_acceptance_sources';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected function casts(): array { return ['manifest' => 'array', 'evidence' => 'array', 'history' => 'array', 'received' => 'integer']; }
}
