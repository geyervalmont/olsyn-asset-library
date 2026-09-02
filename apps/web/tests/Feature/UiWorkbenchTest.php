<?php

test('ui workbench renders its component specimens and templates', function () {
    $this->get(route('ui.index'))
        ->assertOk()
        ->assertSeeText('A precise library, on paper.')
        ->assertSee('Ingest pipeline')
        ->assertDontSee('ui-window-dots', false)
        ->assertSee('The working set.')
        ->assertSee('Library index')
        ->assertSee('Material record')
        ->assertSee('Review queue');
});
