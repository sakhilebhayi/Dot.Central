<?php

namespace App\Http\Controllers;

use App\Models\AgentKnowledge;
use App\Services\PdfTextExtractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Team-scoped CRUD for knowledge documents -- create and delete only, no
 * update. An outdated document gets deleted and re-uploaded rather than
 * edited in place, so "what does the agent actually know" stays auditable
 * (a document's content never silently changes under an agent already
 * using it) -- see the design spec's §3 for the full reasoning.
 */
class AgentKnowledgeController extends Controller
{
    private const MAX_CONTENT_LENGTH = 50_000;

    public function __construct(private readonly PdfTextExtractor $pdfTextExtractor) {}

    public function index(Request $request): View
    {
        $documents = $request->user()->currentTeam
            ? $request->user()->currentTeam->agentKnowledge()->latest()->get()
            : collect();

        return view('agent-knowledge.index', compact('documents'));
    }

    public function create(): View
    {
        return view('agent-knowledge.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'input_mode' => ['required', 'in:text,file'],
            'content' => ['required_if:input_mode,text', 'nullable', 'string'],
            'file' => [
                'required_if:input_mode,file',
                'nullable',
                'file',
                'max:5120', // 5MB, in kilobytes
                'mimes:txt,md,pdf',
            ],
        ]);

        abort_unless($request->user()->currentTeam, 403, 'You need a team before uploading a document.');

        if ($validated['input_mode'] === 'text') {
            $content = $validated['content'];
            $sourceType = 'pasted';
            $originalFilename = null;
        } else {
            $file = $request->file('file');
            $originalFilename = $file->getClientOriginalName();

            if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
                try {
                    $content = $this->pdfTextExtractor->extract($file);
                } catch (\RuntimeException $e) {
                    return back()->withErrors(['file' => $e->getMessage()])->withInput();
                }
                $sourceType = 'pdf';
            } else {
                $content = file_get_contents($file->getRealPath());
                $sourceType = 'text_file';
            }
        }

        $content = mb_substr($content, 0, self::MAX_CONTENT_LENGTH);

        $request->user()->currentTeam->agentKnowledge()->create([
            'title' => $validated['title'],
            'content' => $content,
            'source_type' => $sourceType,
            'original_filename' => $originalFilename,
        ]);

        return redirect()->route('agent-knowledge.index');
    }

    public function destroy(Request $request, AgentKnowledge $agentKnowledge): RedirectResponse
    {
        $agentKnowledge->delete();

        return redirect()->route('agent-knowledge.index');
    }
}
