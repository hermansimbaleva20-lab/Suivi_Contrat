<?php
namespace App\Http\Middleware;
use App\Models\Project;
use App\Models\ProjectPhase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Détermine le projet (en-tête X-Project) et la phase active (en-tête X-Phase) de la requête.
 * Un utilisateur ne peut pas changer de projet en modifiant l'en-tête ; une phase archivée exige l'habilitation « archives ».
 */
class ProjectContext {
 public function handle(Request $request, Closure $next): Response {
  $code=strtoupper($request->header('X-Project','PRISE'));
  abort_unless(in_array($code,['PRISE','PREDIRE'],true),400,'Projet invalide.');
  $project=Project::where('code',$code)->first();
  abort_unless($project,400,'Projet introuvable : exécutez les migrations et le seeder.');
  $user=$request->user();
  if($user){
   abort_unless((int)$user->project_id===(int)$project->id||(bool)$user->is_super_admin,403,'Votre compte n’est pas autorisé à accéder à ce projet.');
  }
  $request->attributes->set('projectCode',$code);
  $request->attributes->set('project',$project);
  $phase=null;
  $requested=(int)$request->header('X-Phase',0);
  if($requested>0){
   $phase=ProjectPhase::where('project_id',$project->id)->where('id',$requested)->first();
   if($phase&&$phase->is_archived){
    $allowed=$user&&((bool)$user->is_super_admin||(bool)$user->can_view_archives||in_array(strtoupper((string)$user->role),['ADMIN','ADMINISTRATEUR','SUPER-ADMIN','SUPER_ADMIN'],true));
    if(!$allowed)$phase=null;
   }
  }
  if(!$phase)$phase=ProjectPhase::where('project_id',$project->id)->where('is_current',true)->first()?:ProjectPhase::where('project_id',$project->id)->orderBy('number')->first();
  $request->attributes->set('phaseId',$phase?(int)$phase->id:0);
  return $next($request);
 }
}
