<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The pipeline is configuration, and configuration drifts. These checks
 * pin the parts that make "a green merge deploys itself, a red one never
 * does" true, and the parts that keep secrets off SSH command lines.
 */
class DeploymentWorkflowsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function workflow(string $file): array
    {
        $path = base_path('.github/workflows/'.$file);
        $this->assertFileExists($path);

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($path);

        return $parsed;
    }

    public function test_ci_runs_on_pull_requests_and_main_and_has_a_quality_gate(): void
    {
        $ci = $this->workflow('ci.yml');

        $this->assertSame('CI', $ci['name']);
        $this->assertArrayHasKey('pull_request', $ci['on']);
        $this->assertSame(['main'], $ci['on']['push']['branches']);

        $gate = $ci['jobs']['quality-gate'];
        $this->assertSame('Quality Gate (all checks must pass)', $gate['name']);
        $this->assertEqualsCanonicalizing(['tests', 'code-style', 'static-php'], $gate['needs']);
    }

    public function test_deploy_runs_only_after_ci_succeeds_on_main_or_by_confirmed_dispatch(): void
    {
        $deploy = $this->workflow('deploy.yml');

        $this->assertSame(['CI'], $deploy['on']['workflow_run']['workflows']);
        $this->assertSame(['completed'], $deploy['on']['workflow_run']['types']);
        $this->assertSame(['main'], $deploy['on']['workflow_run']['branches']);
        $this->assertArrayHasKey('workflow_dispatch', $deploy['on']);
        $this->assertArrayNotHasKey('push', $deploy['on']);

        $job = $deploy['jobs']['deploy'];
        $this->assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $job['if']);
        $this->assertSame('central-production-deploy', $deploy['concurrency']['group']);
        $this->assertFalse($deploy['concurrency']['cancel-in-progress']);
    }

    public function test_bootstrap_is_manual_only(): void
    {
        $bootstrap = $this->workflow('bootstrap.yml');

        $this->assertSame(['workflow_dispatch'], array_keys($bootstrap['on']));
        $this->assertSame('central-production-deploy', $bootstrap['concurrency']['group']);
    }

    public function test_server_logs_workflow_is_manual_and_read_only(): void
    {
        $logs = $this->workflow('server-logs.yml');

        $this->assertSame(['workflow_dispatch'], array_keys($logs['on']));
        $this->assertStringNotContainsString('artisan', (string) file_get_contents(base_path('.github/workflows/server-logs.yml')));
    }

    public function test_no_workflow_passes_a_secret_as_an_ssh_argument(): void
    {
        foreach (['bootstrap.yml', 'deploy.yml', 'server-logs.yml'] as $file) {
            $text = (string) file_get_contents(base_path('.github/workflows/'.$file));
            preg_match_all('/^\s*([A-Z_]+)="\$\{\{ secrets\.([A-Z_]+)[^}]*\}\}" \\\\$/m', $text, $matches);
            foreach ($matches[2] as $secret) {
                $this->assertSame('DEPLOY_PATH', $secret, "$file passes secrets.$secret on the ssh command line");
            }
        }
    }
}
