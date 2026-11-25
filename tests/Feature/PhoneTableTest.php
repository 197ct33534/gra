<?php

namespace Tests\Feature;

use App\Filament\Support\PhoneTable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

class PhoneTableTest extends TestCase
{
    public function test_summary_escapes_text_keeps_markup_and_skips_empty_parts(): void
    {
        $html = PhoneTable::summary(['<b>Khách</b>', null, '', new HtmlString('<i>Trễ</i>'), 'RO-1'])->toHtml();

        $this->assertSame('<span class="phone-only">&lt;b&gt;Khách&lt;/b&gt; · <i>Trễ</i> · RO-1</span>', $html);
    }

    public function test_summary_with_nothing_to_show_renders_nothing(): void
    {
        $this->assertSame('', PhoneTable::summary([null, ''])->toHtml());
    }

    public function test_actions_render_inline_for_desktop_and_a_phone_group_with_distinct_names(): void
    {
        $actions = PhoneTable::actions(fn (): array => [Action::make('print'), Action::make('edit')]);

        $this->assertCount(3, $actions);
        [$print, $edit, $group] = $actions;

        $this->assertSame(['print', 'edit'], [$print->getName(), $edit->getName()]);
        $this->assertStringContainsString('act-inline', (string) $print->getExtraAttributeBag()->get('class'));

        $this->assertInstanceOf(ActionGroup::class, $group);
        $this->assertSame('act-more', $group->getExtraAttributes()['class']);
        $this->assertSame(['printPhone', 'editPhone'], array_values(array_map(fn (Action $a): string => $a->getName(), $group->getActions())));
    }
}
