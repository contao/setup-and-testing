<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Browser;

use Playwright\Frame\FrameLocatorInterface;
use Playwright\Locator\LocatorInterface;
use Playwright\Page\PageInterface;

final readonly class BackendBrowser
{
    public function __construct(private BrowserSession $browser)
    {
    }

    public function browser(): BrowserSession
    {
        return $this->browser;
    }

    public function page(): PageInterface
    {
        return $this->browser->page();
    }

    public function visit(string $path): void
    {
        $this->browser->visit($path);
    }

    public function waitFor(string $selector): void
    {
        $this->page()->locator($selector)->waitFor(['state' => 'attached']);
    }

    public function submitLogin(string $username, string $password): void
    {
        $this->page()->locator('[name="username"]')->fill($username);
        $this->page()->locator('[name="password"]')->fill($password);
        $this->waitForNavigation(fn () => $this->page()->locator('button[name="login"]')->click());
    }

    /**
     * @param array<string, string> $values
     */
    public function submitForm(string $button, array $values = []): void
    {
        foreach ($values as $field => $value) {
            $this->fillField($field, $value);
        }

        $this->waitForNavigation(fn () => $this->page()->getByRole('button', ['name' => $button, 'exact' => true])->click());
    }

    public function submitNew(): void
    {
        $action = $this->visible($this->page()->locator('.header_new'), 'new record action');
        $this->waitForNavigation(static fn () => $action->click());
    }

    public function submitAction(string $label): void
    {
        $this->waitForNavigation(fn () => $this->page()->getByRole('button', ['name' => $label])->click());
    }

    public function check(string $field): void
    {
        $this->checkbox($field)->check();
    }

    public function checkAndWaitForAjax(string $field): void
    {
        $checkbox = $this->checkbox($field);
        $this->waitForAjax(static fn () => $checkbox->check());
    }

    public function select(string $field, string $value): void
    {
        $this->selectField($field)->selectOption($value);
    }

    public function selectAndWaitForAjax(string $field, string $value): void
    {
        $select = $this->selectField($field);

        if ($value === $select->inputValue()) {
            return;
        }

        $this->waitForAjax(static fn () => $select->selectOption($value));
    }

    /**
     * @param callable(): mixed $action
     */
    public function waitForAjax(callable $action): void
    {
        $marker = '__contaoE2eAjax'.bin2hex(random_bytes(8));
        $this->page()->evaluate(\sprintf(
            '(() => { window.addEvent("ajax_change", () => window[%1$s] = true); return null; })()',
            json_encode($marker, JSON_THROW_ON_ERROR),
        ));
        $action();
        $this->page()->waitForFunction('(marker) => window[marker] === true', $marker);
    }

    /**
     * Executes an action and waits for either a Turbo render or a new document.
     *
     * Playwright does not recognize Turbo renders as browser navigations. The marker
     * lets the synchronous PHP bridge register the event listener before the action.
     *
     * @param callable(): void $action
     */
    public function waitForNavigation(callable $action): void
    {
        $marker = '__contaoE2eNavigation'.bin2hex(random_bytes(8));
        $this->page()->evaluate(
            '(marker) => { window[marker] = false; document.addEventListener("turbo:render", () => window[marker] = true, { once: true }); }',
            $marker,
        );
        $action();
        $this->page()->waitForFunction('(marker) => window[marker] !== false', $marker);
    }

    public function clickLink(string $label): void
    {
        $selector = \sprintf('a.navigation:text-is("%s")', $this->escapeCssString($label));
        $this->waitForNavigation(fn () => $this->page()->locator($selector)->click());
    }

    public function clickButton(string $selector): void
    {
        $this->visible($this->page()->locator($selector), \sprintf('button matching "%s"', $selector))->click();
    }

    public function clickTitlePrefix(string $title): void
    {
        $selector = \sprintf('a[title^="%s"]', $this->escapeCssString($title));
        $link = $this->page()->locator($selector.':visible')->first();

        if (0 === $link->count()) {
            $menu = $this->page()->locator('.operations:visible:has('.$selector.')')->first();
            $menu->locator('[data-contao--operations-menu-target="controller"]:visible')->click();
        }

        $this->waitForNavigation(static fn () => $link->click());
    }

    public function fillRichText(string $field, string $text): void
    {
        $this->page()->frameLocator('#ctrl_'.$field.'_ifr')->locator('#tinymce')->fill($text);
    }

    public function selectFile(string $field, string $path, string|null $expectedValue = null): void
    {
        $triggerSelector = '#ft_'.$field;
        $trigger = $this->page()->locator($triggerSelector);
        $this->page()->waitForFunction(
            '(selector) => document.querySelector(selector)?.hasEvent?.("click") === true',
            $triggerSelector,
        );

        $frameSelector = 'iframe[name="simple-modal-iframe"]';
        $trigger->click();
        $frame = $this->page()->frameLocator($frameSelector);
        $frame->locator('#tl_listing')->waitFor(['state' => 'attached']);
        $this->expandFileTree($frame, \dirname($path));
        $selector = \sprintf('input[type="radio"][value="%s"]', $this->escapeCssString($path));
        $frame->locator($selector)->check();
        $this->page()->locator('.simple-modal .btn.primary')->click();
        $this->waitForFileSelection($field, $expectedValue);
    }

    private function fillField(string $field, string $value): void
    {
        $selector = \sprintf('[name="%s"]', $this->escapeCssString($field));
        $input = $this->visible($this->page()->locator($selector), \sprintf('field named "%s"', $field));
        $tagName = $input->evaluate('(element) => element.tagName.toLowerCase()');

        if ('select' === $tagName) {
            $input->selectOption($value);
        } else {
            $input->fill($value);
        }
    }

    private function checkbox(string $field): LocatorInterface
    {
        $selector = \sprintf('input[name="%s"][type="checkbox"]', $this->escapeCssString($field));

        return $this->visible($this->page()->locator($selector), \sprintf('"%s" checkbox', $field));
    }

    private function selectField(string $field): LocatorInterface
    {
        $selector = \sprintf('select[name="%s"]', $this->escapeCssString($field));

        return $this->visible($this->page()->locator($selector), \sprintf('"%s" select', $field));
    }

    private function visible(LocatorInterface $locator, string $description): LocatorInterface
    {
        $locator->first()->waitFor(['state' => 'attached']);

        foreach ($locator->all() as $match) {
            if ($match->isVisible()) {
                return $match;
            }
        }

        throw new \LogicException(\sprintf('Could not find a visible %s at "%s" (%d matches).', $description, $this->page()->url(), $locator->count()));
    }

    private function expandFileTree(FrameLocatorInterface $frame, string $directory): void
    {
        $parts = explode('/', $directory);

        for ($i = 2; $i <= \count($parts); ++$i) {
            $path = implode('/', \array_slice($parts, 0, $i));
            $selector = \sprintf('li[data-id="%s"] a.foldable', $this->escapeCssString($path));
            $folder = $frame->locator($selector);

            if (!str_contains((string) $folder->getAttribute('class'), 'foldable--open')) {
                $folder->click();
            }
        }
    }

    private function waitForFileSelection(string $field, string|null $expectedValue): void
    {
        if (null === $expectedValue) {
            $this->page()->locator('.simple-modal')->waitFor(['state' => 'hidden']);

            return;
        }

        $this->page()->waitForFunction(
            '([selector, expected]) => document.querySelector(selector)?.value.includes(expected)',
            ['#ctrl_'.$field, $expectedValue],
        );
    }

    private function escapeCssString(string $value): string
    {
        return addcslashes($value, "\\\"\n\r\f");
    }
}
