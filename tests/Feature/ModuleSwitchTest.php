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

    public function test_document_switch_overrides_the_app_default_and_the_resolver(): void
    {
        $switch = app(ModuleSwitch::class);
        $wf = $this->makeWorkflow($this->steps(), ['name' => 'req']);
        $resolver = app(WorkflowResolver::class);

        // not set: the app's own default decides
        $this->assertNull($switch->documentSetting(self::DOC_TYPE));
        $this->assertFalse($switch->active(self::DOC_TYPE, false));
        $this->assertTrue($switch->active(self::DOC_TYPE, true));
        $this->assertSame($wf->id, $resolver->resolve(self::DOC_TYPE, [])->id);

        // explicit on beats an app default of off
        $switch->setDocument(self::DOC_TYPE, true);
        $this->assertTrue($switch->active(self::DOC_TYPE, false));

        // explicit off beats an app default of matrix, and the resolver returns nothing
        $switch->setDocument(self::DOC_TYPE, false);
        $this->assertFalse($switch->active(self::DOC_TYPE, true));
        $this->assertNull($resolver->resolve(self::DOC_TYPE, []));
        $this->assertTrue($switch->isEnabledFor('inventory.adjustment'), 'other document types are unaffected');

        // back to not set
        $switch->setDocument(self::DOC_TYPE, null);
        $this->assertNull($switch->documentSetting(self::DOC_TYPE));
        $this->assertSame($wf->id, $resolver->resolve(self::DOC_TYPE, [])->id);
    }

    public function test_module_off_beats_an_explicit_document_on(): void
    {
        $switch = app(ModuleSwitch::class);
        $switch->setDocument(self::DOC_TYPE, true);
        $switch->set('procurement', false);

        $this->assertFalse($switch->active(self::DOC_TYPE, true));
        $this->assertFalse($switch->isEnabledFor(self::DOC_TYPE));
    }

    public function test_document_endpoint(): void
    {
        $editor = $this->userWith(['approval-matrix-index', 'approval-matrix-edit']);
        $url = fn ($t) => route('approval-matrix.documents.update', $t);
        $switch = app(ModuleSwitch::class);

        $this->actingAs($editor)->postJson($url('procurement.cs'), ['state' => 'on'])->assertOk()->assertJson(['state' => 'on']);
        $this->assertTrue($switch->documentSetting('procurement.cs'));
        $this->actingAs($editor)->postJson($url('procurement.cs'), ['state' => 'off'])->assertOk();
        $this->assertFalse($switch->documentSetting('procurement.cs'));
        $this->actingAs($editor)->postJson($url('procurement.cs'), ['state' => 'default'])->assertOk();
        $this->assertNull($switch->documentSetting('procurement.cs'));

        $this->actingAs($editor)->postJson($url('procurement.cs'), ['state' => 'bogus'])->assertStatus(422);
        $this->actingAs($editor)->postJson($url('nope.nothing'), ['state' => 'on'])->assertNotFound();

        $viewer = $this->userWith(['approval-matrix-index']);
        $this->actingAs($viewer)->postJson($url('procurement.cs'), ['state' => 'on'])->assertForbidden();
        $this->actingAs($viewer)->get(route('approval-matrix.workflows.index'))->assertOk()->assertSee('data-doc="procurement.cs"', false);
    }

    public function test_module_of_uses_the_configured_module_not_the_type_prefix(): void
    {
        // host apps add types at runtime whose prefix is not their module (erp-pmd: tender.* belongs to module pmd)
        config(['approvalmatrix.document_types' => config('approvalmatrix.document_types') + [
            'tender.go_no_go' => ['module' => 'pmd', 'label' => 'Tender Go / No-Go'],
        ]]);
        $switch = app(ModuleSwitch::class);

        $this->assertSame('pmd', $switch->moduleOf('tender.go_no_go'));
        $switch->set('pmd', false);
        $this->assertFalse($switch->active('tender.go_no_go', true));
        $this->assertTrue($switch->active(self::DOC_TYPE, true), 'other modules are unaffected');
    }
}
