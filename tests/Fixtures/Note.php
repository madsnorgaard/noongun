<?php

namespace Tests\Fixtures;

use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A minimal tenant-owned model, used to test BelongsToWorkspace before the
 * real domain models exist.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $body
 * @property CarbonImmutable|null $remind_at
 */
class Note extends Model
{
    use BelongsToWorkspace;

    protected $table = 'test_notes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime'];
    }

    public static function createTable(): void
    {
        Schema::create('test_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('body');
            $table->timestamp('remind_at')->nullable();
            $table->timestamps();
        });
    }
}
