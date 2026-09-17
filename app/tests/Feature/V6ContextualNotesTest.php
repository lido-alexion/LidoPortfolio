<?php

namespace Tests\Feature;

use App\Models\ContextualNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V6ContextualNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_add_update_list_and_delete_contextual_notes(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/contextual-notes', [
                'context_key' => 'dashboard',
                'profile_id' => $profile->id,
                'subject_type' => 'stock',
                'subject_id' => 'INFY',
                'body' => 'Watch earnings reaction.',
            ])->assertCreated()
            ->assertJsonPath('data.body', 'Watch earnings reaction.');

        $noteId = $response->json('data.id');
        $this->postJson('/api/contextual-notes', [
            'context_key' => 'dashboard',
            'profile_id' => $profile->id,
            'subject_type' => 'stock',
            'subject_id' => 'INFY',
            'body' => 'Updated by upsert.',
        ])->assertOk();
        $this->assertSame(1, ContextualNote::query()->count());

        $this->getJson('/api/contextual-notes?context_key=dashboard&profile_id='.$profile->id.'&subject_type=stock&subject_id=INFY')
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Updated by upsert.');

        $this->putJson('/api/contextual-notes/'.$noteId, ['body' => 'Inline edit'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Inline edit');

        $this->deleteJson('/api/contextual-notes/'.$noteId)->assertOk();
        $this->assertSame(0, ContextualNote::query()->count());
    }

    public function test_contextual_notes_are_personal_and_profile_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $otherProfile = $this->defaultPortfolioFor($other);

        $note = ContextualNote::query()->create([
            'user_id' => $owner->id,
            'profile_id' => $ownerProfile->id,
            'context_key' => 'holdings',
            'body' => 'Private',
        ]);

        $this->actingAs($other)->withProfileHeader($other, $otherProfile)
            ->getJson('/api/contextual-notes?context_key=holdings&profile_id='.$ownerProfile->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->putJson('/api/contextual-notes/'.$note->id, ['body' => 'Nope'])->assertNotFound();
        $this->deleteJson('/api/contextual-notes/'.$note->id)->assertNotFound();
        $this->postJson('/api/contextual-notes', [
            'context_key' => 'holdings',
            'profile_id' => $ownerProfile->id,
            'body' => 'Nope',
        ])->assertNotFound();
    }

    public function test_personal_token_scopes_do_not_bypass_contextual_note_ownership(): void
    {
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $foreign = User::factory()->create();
        $foreignProfile = $this->defaultPortfolioFor($foreign);
        $foreignNote = ContextualNote::query()->create([
            'user_id' => $foreign->id,
            'profile_id' => $foreignProfile->id,
            'context_key' => 'dashboard',
            'body' => 'Foreign note',
        ]);
        $readToken = $owner->createToken('Notes reader', ['notes:read'])->plainTextToken;
        $writeToken = $owner->createToken('Notes writer', ['notes:write'])->plainTextToken;

        $this->flushHeaders()->withToken($readToken)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->getJson('/api/contextual-notes?context_key=dashboard&profile_id='.$foreignProfile->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($writeToken)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->deleteJson('/api/contextual-notes/'.$foreignNote->id)
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($readToken)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->postJson('/api/contextual-notes', ['context_key' => 'dashboard', 'body' => 'Denied'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This API token is missing the required scope.');
    }
}
