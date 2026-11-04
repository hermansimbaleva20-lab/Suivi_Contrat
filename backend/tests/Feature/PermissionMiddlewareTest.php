<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureProjectPermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Contrôle d'accès aux modules (middleware « permission »), sans base de données. */
class PermissionMiddlewareTest extends TestCase
{
    private function passe(?User $user, string $permissions): int
    {
        $request = Request::create('/api/test');
        $request->setUserResolver(fn () => $user);
        try {
            return (new EnsureProjectPermission())->handle($request, fn () => new Response('ok'), $permissions)->getStatusCode();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    private function user(array $attributs): User
    {
        return (new User())->forceFill(array_merge(['role' => 'USER', 'is_super_admin' => false], $attributs));
    }

    public function test_sans_utilisateur_401(): void
    {
        $this->assertSame(401, $this->passe(null, 'can_view_reports'));
    }

    public function test_utilisateur_sans_habilitation_403(): void
    {
        $this->assertSame(403, $this->passe($this->user([]), 'can_view_reports'));
        $this->assertSame(403, $this->passe($this->user(['can_view_alerts' => true]), 'can_view_reports'));
    }

    public function test_une_des_habilitations_suffit(): void
    {
        $this->assertSame(200, $this->passe($this->user(['can_view_situation' => true]), 'can_view_reports|can_view_situation'));
    }

    public function test_admin_et_super_admin_ont_tout(): void
    {
        $this->assertSame(200, $this->passe($this->user(['role' => 'administrateur']), 'can_manage_settings'));
        $this->assertSame(200, $this->passe($this->user(['is_super_admin' => true]), 'can_manage_settings'));
    }
}
