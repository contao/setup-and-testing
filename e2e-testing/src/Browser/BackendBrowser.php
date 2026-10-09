<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Browser;

use Playwright\Frame\FrameLocatorInterface;
use Playwright\Locator\LocatorInterface;
use Playwright\Page\PageInterface;

final readonly class BackendBrowser
{
    public function __construct(
        private BrowserSession $browser,
        private BackendLoginSessionCache $loginSessions,
    ) {
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

    public function loginOrReuseSessionAs(string $username = 'k.jones', string $password = 'kevinjones'): self
    {
        $userAgent = $this->page()->evaluate('() => navigator.userAgent');

        if (!\is_string($userAgent)) {
            throw new \RuntimeException('Could not determine the browser user agent.');
        }

        $key = $this->loginSessions->key($username, $password, $userAgent);
        $session = $this->loginSessions->get($key);
        $this->clearBackendCookies();

        if ($session) {
            $this->browser->context()->addCookies($this->loginSessions->restore($session));
            $this->visit('/contao');

            if ($session->userLabel === $this->authenticatedUserLabel()) {
                return $this;
            }

            $this->loginSessions->forget($key);
            $this->clearBackendCookies();
        }

        $this->visit('/contao/login');
        $this->submitLogin($username, $password);

        $userLabel = $this->authenticatedUserLabel();

        if (null === $userLabel) {
            throw new \RuntimeException(\sprintf('Could not log into the Contao backend as "%s".', $username));
        }

        $this->loginSessions->save($key, $this->browser->context()->cookies([$this->browser->uri('/contao')]), $userLabel);

        return $this;
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
     * Executes an action and waits for either a Turbo visit or a new document.
     *
     * Playwright does not recognize Turbo visits as browser navigations. The marker
     * lets the synchronous PHP bridge register the event listener before the action.
     *
     * @param callable(): void $action
     */
    public function waitForNavigation(callable $action): void
    {
        $marker = $this->registerNavigationMarker();
        $action();
        $this->page()->waitForFunction('(marker) => window[marker] === true || (window[marker] === undefined && document.readyState !== "loading")', $marker);
    }

    /**
     * @param callable(): void $action
     */
    public function waitForTurboNavigation(callable $action): void
    {
        $marker = $this->registerNavigationMarker();
        $action();
        $this->page()->waitForFunction('(marker) => window[marker] === true', $marker);
    }

    /**
     * @param callable(): void $action
     */
    public function waitForFullNavigation(callable $action): void
    {
        $marker = $this->registerNavigationMarker();
        $action();
        $this->page()->waitForFunction('(marker) => window[marker] === undefined && document.readyState !== "loading"', $marker);
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
        $this->page()->frameLocator('#ctrl_'.$field.'_ifr')->locator('.mce-content-body')->fill($text);
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

    private function clearBackendCookies(): void
    {
        $cookies = $this->browser->context()->cookies([
            $this->browser->uri('/contao'),
            $this->browser->uri('/contao/login'),
        ]);

        foreach ($cookies as &$cookie) {
            $cookie['expires'] = 1;
        }

        if ([] !== $cookies) {
            $this->browser->context()->addCookies($cookies);
        }
    }

    private function authenticatedUserLabel(): string|null
    {
        $profile = $this->page()->locator('#tmenu .profile button, #profileButton');

        if (0 === $profile->count() || 0 === $this->page()->locator('a[href*="/contao/logout"]')->count()) {
            return null;
        }

        return $profile->first()->textContent();
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

    private function registerNavigationMarker(): string
    {
        $marker = '__contaoE2eNavigation'.bin2hex(random_bytes(8));
        $this->page()->evaluate(
            '(marker) => { window[marker] = false; document.addEventListener("turbo:load", () => window[marker] = true, { once: true }); }',
            $marker,
        );

        return $marker;
    }
}
