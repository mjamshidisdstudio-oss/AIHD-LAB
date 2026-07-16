<?php

namespace Tests\Feature\Admin;

use App\Enums\CommentStatus;
use App\Models\Service;
use App\Models\ServiceComment;
use App\Models\ServiceVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCommentApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_guest_cannot_list_hide_or_reply(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id]);
        $comment = ServiceComment::factory()->create(['service_version_id' => $version->id]);

        $this->getJson("/api/admin/services/{$service->id}/comments")->assertUnauthorized();
        $this->patchJson("/api/admin/comments/{$comment->id}", ['status' => 'hidden'])->assertUnauthorized();
        $this->postJson("/api/admin/comments/{$comment->id}/reply", ['body' => 'hi'])->assertUnauthorized();
    }

    public function test_lists_comments_across_all_versions_of_the_service_with_replies_nested(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();
        $v1 = ServiceVersion::factory()->create(['service_id' => $service->id, 'version_no' => 1]);
        $v2 = ServiceVersion::factory()->create(['service_id' => $service->id, 'version_no' => 2]);

        $rootV1 = ServiceComment::factory()->create(['service_version_id' => $v1->id, 'body' => 'root on v1']);
        ServiceComment::factory()->replyTo($rootV1)->create(['body' => 'reply on v1']);
        ServiceComment::factory()->create(['service_version_id' => $v2->id, 'body' => 'root on v2']);

        $all = $this->getJson("/api/admin/services/{$service->id}/comments")->assertOk();
        $this->assertCount(2, $all->json('data'));

        $onlyV1 = $this->getJson("/api/admin/services/{$service->id}/comments?service_version_id={$v1->id}")->assertOk();
        $this->assertCount(1, $onlyV1->json('data'));
        $this->assertCount(1, $onlyV1->json('data.0.replies'));
    }

    public function test_admin_can_toggle_a_comment_hidden_and_back_to_published(): void
    {
        $this->actingAsAdmin();
        $comment = ServiceComment::factory()->create(['status' => CommentStatus::Published]);

        $this->patchJson("/api/admin/comments/{$comment->id}", ['status' => 'hidden'])
            ->assertOk()
            ->assertJsonPath('data.status', 'hidden');

        $this->patchJson("/api/admin/comments/{$comment->id}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_admin_can_reply_to_a_root_comment(): void
    {
        $admin = $this->actingAsAdmin();
        $comment = ServiceComment::factory()->create();

        $response = $this->postJson("/api/admin/comments/{$comment->id}/reply", ['body' => 'Thanks for the feedback!'])
            ->assertCreated();

        $this->assertSame('admin:'.$admin->id, $response->json('data.user_ref'));
        $this->assertSame($comment->id, $response->json('data.parent_id'));
        $this->assertDatabaseHas('service_comments', [
            'parent_id' => $comment->id,
            'body' => 'Thanks for the feedback!',
        ]);
    }

    public function test_cannot_reply_to_a_reply(): void
    {
        $this->actingAsAdmin();
        $root = ServiceComment::factory()->create();
        $reply = ServiceComment::factory()->replyTo($root)->create();

        $this->postJson("/api/admin/comments/{$reply->id}/reply", ['body' => 'nested'])
            ->assertStatus(422);
    }
}
