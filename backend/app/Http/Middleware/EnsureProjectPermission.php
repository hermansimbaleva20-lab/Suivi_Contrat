<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnsureProjectPermission {
 public function handle(Request $request, Closure $next, string $permissions): Response {
  $user=$request->user();abort_unless($user,401);
  $role=strtoupper((string)$user->role);
  if($user->is_super_admin||in_array($role,['ADMIN','ADMINISTRATEUR','SUPER-ADMIN','SUPER_ADMIN'],true))return $next($request);
  foreach(explode('|',$permissions) as $permission){if((bool)$user->{$permission})return $next($request);}
  abort(403,'Vous ne disposez pas de l’habilitation nécessaire pour accéder à ce module.');
 }
}
