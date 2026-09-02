<?php

test('ui workbench renders its component specimens and templates', function () {
    $this->get(route('ui.index'))
        ->assertOk()
        ->assertSeeText('A precise library, on paper.')
        ->assertSee('The working set.')
        ->assertSee('Library index')
        ->assertSee('Material record')
        ->assertSee('Review queue');
});
