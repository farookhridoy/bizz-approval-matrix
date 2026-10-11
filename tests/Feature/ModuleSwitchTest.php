<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Bizzsol\ApprovalMatrix\Models\ApprovalModuleSetting;
use Bizzsol\ApprovalMatrix\Services\ModuleSwitch;
use Bizzsol\ApprovalMatrix\Services\WorkflowResolver;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Spatie\Permission\Models\Permission;

class ModuleSwitchTest extends ApprovalTestCase
{
    private function steps(): array
    {
        return [['approver_type' => 'reporting_head']];
    }

    public function test_module_without_a_row_follows_the_default(): void
    {
        $switch = app(ModuleSwitch::class);

        config(['approvalmatrix.default_module_enabled' => true]);
        $this->assertTrue($switch->isEnabled('procurement'));

        config(['approvalmatrix.default_module_enabled' => false]);
        $this->assertFalse($switch->isEnabled('procurement'));
    }

    public function test_disabled_module_resolves_no_workflow_and_enabling_restores_it(): void
    {
        $wf = $this->makeWorkflow($this->steps(), ['name' => 'req']);
        $resolver = app(WorkflowResolver::class);
        $switch = app(ModuleSwitch::class);

        $this->assertSame($wf->id, $resolver->resolve(self::DOC_TYPE, [])->id);

        $switch->set('procurement', false);
        $this->assertNull($resolver->resolve(self::DOC_TYPE, []));
        $this->assertTrue($resolver->candidates(self::DOC_TYPE, [])->isEmpty());
        $this->assertSame('active', $wf->fresh()->state, 'the workflow itself is left untouched');

        $switch->set('procurement', true);
        $this->assertSame($wf->id, $resolver->resolve(self::DOC_TYPE, [])->id);
    }

    public function test_switch_is_per_module(): void
    {
        $switch = app(ModuleSwitch::class);
        $switch->set('inventory', false);

        $this->assertFalse($switch->isEnabledFor('inventory.adjustment'));
        $this->assertTrue($switch->isEnabledFor(self::DOC_TYPE));
        $this->assertSame('procurement', $switch->moduleOf(self::DOC_TYPE));
        $this->assertSame(1, ApprovalModuleSetting::where('module', 'inventory')->count());
    }

    private function userWith(array $perms)
    {
        $u = $this->makeUser('sw');
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $u->givePermissionTo($perms);

        return $u;
    }

    public function test_dashboard_toggle_endpoint_saves_the_setting(): void
    {
        $editor = $this->userWith(['approval-matrix-index', 'approval-matrix-edit']);

        $this->actingAs($editor)->postJson(route('approval-matrix.modules.update', 'hrms'), ['enabled' => 0])
            ->assertOk()->assertJson(['success' => true, 'enabled' => false]);
        $this->assertFalse(ApprovalModuleSetting::where('module', 'hrms')->value('enabled') == 1);
        $this->assertSame($editor->id, (int) ApprovalModuleSetting::where('module', 'hrms')->value('updated_by'));

        $this->actingAs($editor)->postJson(route('approval-matrix.modules.update', 'hrms'), ['enabled' => 1])->assertOk()->assertJson(['enabled' => true]);
        $this->actingAs($editor)->postJson(route('approval-matrix.modules.update', 'not-a-module'), ['enabled' => 0])->assertNotFound();
    }

    public function test_toggle_needs_edit_permission_and_page_lists_every_module(): void
    {
        $viewer = $this->userWith(['approval-matrix-index']);

        $this->actingAs($viewer)->postJson(route('approval-matrix.modules.update', 'hrms'), ['enabled' => 0])->assertForbidden();
        $this->assertSame(0, ApprovalModuleSetting::where('module', 'hrms')->count());

        $page = $this->actingAs($viewer)->get(route('approval-matrix.workflows.index'))->assertOk();
        foreach (config('approvalmatrix.modules') as $m) {
            $page->assertSee('data-module="'.$m.'"', false);
        }
    }
}
