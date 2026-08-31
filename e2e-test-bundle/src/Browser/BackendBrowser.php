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
        $this->navigate(fn () => $this->page()->locator('button[name="login"]')->click());
    }

    /**
     * @param array<string, string> $values
     */
    public function submitForm(string $button, array $values = []): void
    {
        $this->waitForBackend();

        foreach ($values as $field => $value) {
            $this->fillField($field, $value);
        }

        $button = $this->visible(
            $this->page()->getByRole('button', ['name' => $button, 'exact' => true]),
            \sprintf('submit button labeled "%s"', $button),
        );
        $this->navigate(static fn () => $button->click());
    }

    public function submitNew(): void
    {
        $this->waitForBackend();
        $action = $this->visible($this->page()->locator('.header_new'), 'new record action');
        $this->navigate(static fn () => $action->click());
    }

    public function submitAction(string $label): void
    {
        $this->waitForBackend();

        foreach ($this->page()->locator('button[type="submit"]')->all() as $button) {
            if ($button->isVisible() && $this->buttonMatches($button, $label)) {
                $this->navigate(static fn () => $button->click());

                return;
            }
        }

        throw new \LogicException(\sprintf('Could not find a visible submit action labeled "%s".', $label));
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

    public function clickLink(string $label): void
    {
        $link = $this->visible(
            $this->page()->getByRole('link', ['name' => $label, 'exact' => true]),
            \sprintf('link labeled "%s"', $label),
        );
        $this->navigate(static fn () => $link->click());
    }

    public function clickButton(string $selector): void
    {
        $this->waitForBackend();
        $this->visible($this->page()->locator($selector), \sprintf('button matching "%s"', $selector))->click();
    }

    public function clickTitlePrefix(string $title): void
    {
        $this->waitForBackend();
        $selector = \sprintf('a[title^="%s"]', $this->escapeCssString($title));
        $link = $this->page()->locator($selector)->first();
        $link->waitFor(['state' => 'attached']);

        $href = $link->getAttribute('href');

        if (null === $href) {
            throw new \LogicException(\sprintf('The link whose title starts with "%s" has no target.', $title));
        }

        $this->browser->visit($href);
    }

    public function fillRichText(string $field, string $text): void
    {
        $this->waitForBackend();
        $this->page()->frameLocator('#ctrl_'.$field.'_ifr')->locator('#tinymce')->fill($text);
    }

    public function selectFile(string $field, string $path, string|null $expectedValue = null): void
    {
        $this->waitForBackend();
        $triggerSelector = '#ft_'.$field;
        $trigger = $this->page()->locator($triggerSelector);
        $trigger->waitForFunction('(element) => element.hasEvent?.("click") === true');

        $frameSelector = 'iframe[name="simple-modal-iframe"]';
        $trigger->click();
        $frame = $this->page()->frameLocator($frameSelector);
        $frame->locator('#tl_listing')->waitFor(['state' => 'attached']);
        $this->expandFileTree($frame, \dirname($path));
        $selector = \sprintf('input[type="radio"][value="%s"]', $this->escapeCssString($path));
        $frame->locator($selector)->click();
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

    private function buttonMatches(LocatorInterface $button, string $label): bool
    {
        $image = $button->locator('img[alt]');
        $descriptions = [
            trim($button->innerText()),
            (string) $button->getAttribute('title'),
            $image->count() ? (string) $image->first()->getAttribute('alt') : '',
        ];

        return str_contains(implode("\0", $descriptions), $label);
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

        $this->page()->locator('#ctrl_'.$field)->waitForFunction(
            '(element, expected) => element.value.includes(expected)',
            $expectedValue,
        );
    }

    /**
     * @param callable(): void $action
     */
    private function navigate(callable $action): void
    {
        $marker = bin2hex(random_bytes(8));
        $this->page()->locator('body')->evaluate(
            '(element, marker) => element.dataset.contaoE2eNavigation = marker',
            $marker,
        );
        $action();
        $this->page()->waitForFunction(
            '(marker) => document.body?.dataset.contaoE2eNavigation !== marker',
            $marker,
        );
    }

    private function escapeCssString(string $value): string
    {
        return addcslashes($value, "\\\"\n\r\f");
    }

    private function waitForBackend(): void
    {
        $this->page()->locator('body.js')->waitFor();
    }
}
