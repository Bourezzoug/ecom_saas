<?php

namespace App\Http\Controllers\Editor\Concerns;

use App\Models\Page;
use App\Models\PageSection;
use App\Models\Project;

/**
 * Pages and sections are only ever looked up inside the (already authorised)
 * project, so an id from another project is a 404.
 */
trait FindsProjectRecords
{
    protected function findPage(Project $project, string $pageId): Page
    {
        return $project->pages()->whereKey($pageId)->firstOrFail();
    }

    protected function findSection(Project $project, string $sectionId): PageSection
    {
        return PageSection::query()
            ->whereKey($sectionId)
            ->whereHas('page', fn ($q) => $q->where('project_id', $project->id))
            ->with('page.project')
            ->firstOrFail();
    }
}
