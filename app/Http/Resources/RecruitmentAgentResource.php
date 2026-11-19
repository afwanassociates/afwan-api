<?php

namespace App\Http\Resources;

use App\Models\RecruitmentAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecruitmentAgent
 */
class RecruitmentAgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'rl_number' => $this->rl_number,
            'phone' => $this->phone,
            'address' => $this->address,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'is_own' => (bool) $this->is_own,
            // Management list only.
            'current_count' => $this->when(isset($this->current_count), fn () => (int) $this->current_count),
            'counted_count' => $this->when(isset($this->counted_count), fn () => (int) $this->counted_count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The short shape embedded in passports and payments.
     *
     * @return array{id: int, code: string, name: string, rl_number: string, is_own: bool}
     */
    public static function summary(RecruitmentAgent $agent): array
    {
        return ['id' => $agent->id, 'code' => $agent->code, 'name' => $agent->name, 'rl_number' => $agent->rl_number, 'is_own' => (bool) $agent->is_own];
    }
}
