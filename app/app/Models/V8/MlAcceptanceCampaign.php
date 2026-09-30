<?php
namespace App\Models\V8;
use Illuminate\Database\Eloquent\Model;
class MlAcceptanceCampaign extends Model
{
    protected $table = 'stox_ml_acceptance_campaigns';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected function casts(): array { return ['cutoff_date' => 'date', 'identity' => 'array', 'active_models' => 'array', 'horizons' => 'array', 'history' => 'array', 'qualified_at' => 'datetime']; }
}
