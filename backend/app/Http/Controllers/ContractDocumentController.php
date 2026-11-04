<?php
namespace App\Http\Controllers;

use App\Support\Ctx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContractDocumentController extends Controller
{
    public function store(Request $r, int $id)
    {
        Ctx::need('can_manage_contracts');
        $c = DB::table('contracts')->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($c, 404, 'Contrat introuvable.');
        $r->validate(['file' => 'required|file|max:20480|mimes:pdf,doc,docx']);
        $file = $r->file('file');
        $stored = Str::uuid()->toString() . '.' . strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('contract_documents', $stored, 'local');
        $docId = DB::table('contract_documents')->insertGetId([
            'contract_id' => $id, 'original_name' => $file->getClientOriginalName(), 'storage_path' => $path, 'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(), 'uploaded_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Ctx::audit('AJOUT_DOCUMENT', 'ContractDocument', $docId, "Document {$file->getClientOriginalName()} ajouté au contrat {$c->reference}.");
        return response()->json(['message' => 'Document ajouté au dossier du contrat.', 'id' => $docId], 201);
    }

    private function find(int $id)
    {
        $d = DB::table('contract_documents as d')->join('contracts as c', 'c.id', '=', 'd.contract_id')->where('d.id', $id)->where('c.project_id', Ctx::projectId())->select('d.*', 'c.reference')->first();
        abort_unless($d, 404, 'Document introuvable.');
        return $d;
    }

    public function download(int $id)
    {
        $d = $this->find($id);
        abort_unless(Storage::disk('local')->exists($d->storage_path), 404, 'Fichier introuvable sur le serveur.');
        Ctx::audit('TELECHARGEMENT_DOCUMENT', 'ContractDocument', $id, "Document {$d->original_name} téléchargé.");
        return Storage::disk('local')->download($d->storage_path, $d->original_name);
    }

    public function destroy(int $id)
    {
        Ctx::need('can_manage_contracts');
        $d = $this->find($id);
        Storage::disk('local')->delete($d->storage_path);
        DB::table('contract_documents')->where('id', $id)->delete();
        Ctx::audit('SUPPRESSION_DOCUMENT', 'ContractDocument', $id, "Document {$d->original_name} supprimé du contrat {$d->reference}.");
        return ['message' => 'Document supprimé.'];
    }
}
