/**
 * Parity harness: reads render jobs as JSON on stdin, prints the HTML each
 * job produces with the JS renderer (one JSON array on stdout).
 *
 *   echo '[{"definition":{...},"id":"x","content":{},"style":{},"data":{},"site":{...},"links":{}}]' | npm run render
 *
 * "links" maps "kind:value" → URL, standing in for the PHP link resolver closure.
 */
import { buildViewModel, render, type SectionDefinition } from '../src/index.ts';

type Job = {
    definition: SectionDefinition;
    id: string;
    content: Record<string, unknown>;
    style: Record<string, unknown>;
    data: Record<string, unknown>;
    site: Record<string, unknown>;
    links?: Record<string, string>;
};

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => (input += chunk));
process.stdin.on('end', () => {
    const jobs = JSON.parse(input) as Job[];

    const html = jobs.map((job) => {
        const links = job.links ?? {};
        const viewModel = buildViewModel(job.definition, job.id, job.content, job.style, job.data, {
            site: job.site as never,
            resolveLink: (target) => links[`${target.kind}:${target.value}`] ?? null,
        });

        return render(job.definition, viewModel);
    });

    process.stdout.write(JSON.stringify(html));
});
