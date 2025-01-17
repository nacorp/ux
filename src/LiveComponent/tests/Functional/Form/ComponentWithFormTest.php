<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Symfony\UX\LiveComponent\Tests\Functional\Form;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\UX\LiveComponent\Tests\Fixtures\Component\FormWithCollectionTypeComponent;
use Symfony\UX\LiveComponent\Tests\Fixtures\Form\BlogPostFormType;
use Symfony\UX\LiveComponent\Tests\LiveComponentTestHelper;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * @author Jakub Caban <kuba.iluvatar@gmail.com>
 */
class ComponentWithFormTest extends KernelTestCase
{
    use Factories;
    use HasBrowser;
    use LiveComponentTestHelper;
    use ResetDatabase;

    public function testFormValuesRebuildAfterFormChanges(): void
    {
        $dehydratedProps = $this->dehydrateComponent($this->mountComponent('form_with_collection_type'));
        $token = null;

        $this->browser()
            ->get('/_components/form_with_collection_type?data='.urlencode(json_encode($dehydratedProps)))
            ->use(function (Crawler $crawler) use (&$dehydratedProps, &$token) {
                // mimic user typing
                $dehydratedProps['blog_post_form']['content'] = 'changed description by user';
                $dehydratedProps['validatedFields'] = ['blog_post_form.content'];
                $token = $crawler->filter('div')->first()->attr('data-live-csrf-value');
            })

            // post to action, which will add a new embedded comment
            ->post('/_components/form_with_collection_type/addComment', [
                'body' => json_encode(['data' => $dehydratedProps]),
                'headers' => ['X-CSRF-TOKEN' => $token],
            ])
            ->assertStatus(422)
            // look for original embedded form
            ->assertContains('<textarea id="blog_post_form_comments_0_content"')
            // look for new embedded form
            ->assertContains('<textarea id="blog_post_form_comments_1_content"')
            // changed text is still present
            ->assertContains('changed description by user</textarea>')
            // check that validation happened and stuck
            ->assertContains('The content field is too short')
            // make sure the title field did not suddenly become validated
            ->assertNotContains('The title field should not be blank')
            ->use(function (Crawler $crawler) use (&$dehydratedProps, &$token) {
                $div = $crawler->filter('[data-controller="live"]');
                $liveProps = json_decode($div->attr('data-live-props-value'), true);
                // make sure the 2nd collection type was initialized, that it didn't
                // just "keep" the empty array that we set it to in the component
                $this->assertEquals(
                    [
                        ['content' => ''],
                        ['content' => ''],
                    ],
                    $liveProps['blog_post_form']['comments']
                );

                // fake that this field was being validated
                $dehydratedProps = $liveProps;
                $liveProps['validatedFields'][] = 'blog_post_form.0.comments.content';
                $token = $div->attr('data-live-csrf-value');
            })

            // post to action, which will remove the original embedded comment
            ->post('/_components/form_with_collection_type/removeComment', [
                'body' => json_encode(['data' => $dehydratedProps, 'args' => ['index' => '0']]),
                'headers' => ['X-CSRF-TOKEN' => $token],
            ])
            ->assertStatus(422)
            // the original embedded form should be gone
            ->assertNotContains('<textarea id="blog_post_form_comments_0_content"')
            // the added one should still be present
            ->assertContains('<textarea id="blog_post_form_comments_1_content"')
            ->use(function (Crawler $crawler) {
                $div = $crawler->filter('[data-controller="live"]');
                $liveProps = json_decode($div->attr('data-live-props-value'), true);
                // the embedded validated field should be gone, since its data is gone
                $this->assertEquals(
                    ['blog_post_form.content'],
                    $liveProps['validatedFields']
                );
            })
        ;
    }

    public function testFormRemembersValidationFromInitialForm(): void
    {
        /** @var FormFactoryInterface $formFactory */
        $formFactory = self::getContainer()->get('form.factory');

        $form = $formFactory->create(BlogPostFormType::class);
        $form->submit(['title' => '', 'content' => '']);

        $mounted = $this->mountComponent('form_with_collection_type', [
            'form' => $form->createView(),
        ]);

        /** @var FormWithCollectionTypeComponent $component */
        $component = $mounted->getComponent();

        // component should recognize that it is already submitted
        $this->assertTrue($component->isValidated);

        $dehydratedProps = $this->dehydrateComponent($mounted);
        $dehydratedProps['blog_post_form']['content'] = 'changed description';
        $dehydratedProps['validatedFields'][] = 'blog_post_form.content';

        $this->browser()
            ->get('/_components/form_with_collection_type?data='.urlencode(json_encode($dehydratedProps)))
            // normal validation happened
            ->assertContains('The content field is too short')
            // title is STILL validated as all fields should be validated
            ->assertContains('The title field should not be blank')
        ;
    }

    public function testHandleCheckboxChanges(): void
    {
        $mounted = $this->mountComponent(
            'form_with_many_different_fields_type',
            [
                'initialData' => [
                    'choice_multiple' => [2],
                    'select_multiple' => [2],
                    'checkbox_checked' => true,
                ],
            ]
        );

        $dehydratedProps = $this->dehydrateComponent($mounted);
        $bareForm = [
            'text' => '',
            'textarea' => '',
            'range' => '',
            'choice' => '',
            'choice_expanded' => '',
            'choice_multiple' => ['2'],
            'select_multiple' => ['2'],
            'checkbox' => null,
            'checkbox_checked' => '1',
            'file' => '',
            'hidden' => '',
        ];

        $this->browser()
            ->throwExceptions()
            ->get('/_components/form_with_many_different_fields_type?data='.urlencode(json_encode($dehydratedProps)))
            ->assertSuccessful()
            ->assertContains('<input type="checkbox" id="form_choice_multiple_1" name="form[choice_multiple][]" value="2" checked="checked" />')
            ->assertContains('<input type="checkbox" id="form_choice_multiple_0" name="form[choice_multiple][]" value="1" />')
            ->assertContains('<input type="checkbox" id="form_checkbox" name="form[checkbox]" required="required" value="1" />')
            ->assertContains('<input type="checkbox" id="form_checkbox_checked" name="form[checkbox_checked]" required="required" value="1" checked="checked" />')
            ->use(function (Crawler $crawler) use (&$dehydratedProps, &$bareForm) {
                $props = json_decode(
                    $crawler->filter('div')->first()->attr('data-live-props-value'),
                    true
                );
                self::assertEquals($bareForm, $props['form']);

                // check both multiple fields
                $bareForm['choice_multiple'] = ['1', '2'];

                $dehydratedProps['form'] = $bareForm;
            })
            ->get('/_components/form_with_many_different_fields_type?data='.urlencode(json_encode($dehydratedProps)))
            ->assertContains('<input type="checkbox" id="form_choice_multiple_1" name="form[choice_multiple][]" value="2" checked="checked" />')
            ->assertContains('<input type="checkbox" id="form_choice_multiple_0" name="form[choice_multiple][]" value="1" checked="checked" />')
            ->use(function (Crawler $crawler) use (&$dehydratedProps, &$bareForm) {
                $props = json_decode(
                    $crawler->filter('div')->first()->attr('data-live-props-value'),
                    true
                );
                self::assertEquals($bareForm, $props['form']);

                // uncheck multiple, check single checkbox
                $bareForm['checkbox'] = '1';
                $bareForm['choice_multiple'] = [];

                // uncheck previously checked checkbox
                $bareForm['checkbox_checked'] = null;

                $dehydratedProps['form'] = $bareForm;
            })
            ->get('/_components/form_with_many_different_fields_type?data='.urlencode(json_encode($dehydratedProps)))
            ->assertContains('<input type="checkbox" id="form_choice_multiple_1" name="form[choice_multiple][]" value="2" />')
            ->assertContains('<input type="checkbox" id="form_choice_multiple_0" name="form[choice_multiple][]" value="1" />')
            ->assertContains('<input type="checkbox" id="form_checkbox" name="form[checkbox]" required="required" value="1" checked="checked" />')
            ->assertContains('<input type="checkbox" id="form_checkbox_checked" name="form[checkbox_checked]" required="required" value="1" />')
            ->use(function (Crawler $crawler) use (&$dehydratedProps, &$bareForm) {
                $props = json_decode(
                    $crawler->filter('div')->first()->attr('data-live-props-value'),
                    true
                );
                self::assertEquals($bareForm, $props['form']);

                // check both multiple fields
                $bareForm['select_multiple'] = ['2', '1'];

                $dehydratedProps['form'] = $bareForm;
            })
            ->get('/_components/form_with_many_different_fields_type?data='.urlencode(json_encode($dehydratedProps)))
            ->assertContains('<option value="2" selected="selected">')
            ->assertContains('<option value="1" selected="selected">')
            ->use(function (Crawler $crawler) use ($bareForm) {
                $props = json_decode(
                    $crawler->filter('div')->first()->attr('data-live-props-value'),
                    true
                );
                self::assertEquals($bareForm, $props['form']);
            })
        ;
    }

    public function testLiveCollectionTypeAddButtonsByDefault(): void
    {
        $dehydrated = $this->dehydrateComponent($this->mountComponent('form_with_live_collection_type'));

        $this->browser()
            ->get('/_components/form_with_live_collection_type?data='.urlencode(json_encode($dehydrated)))
            ->use(function (Crawler $crawler) use (&$dehydrated, &$token) {
                // mimic user typing
                $dehydrated['blog_post_form']['content'] = 'changed description by user';
                $dehydrated['validatedFields'] = ['blog_post_form.content'];
                $token = $crawler->filter('div')->first()->attr('data-live-csrf-value');
            })
            ->assertContains('<button type="button" id="blog_post_form_comments_add"')
            ->assertContains('<button type="button" id="blog_post_form_comments_0_delete"')
        ;
    }

    public function testLiveCollectionTypeFieldsAddedAndRemoved(): void
    {
        $dehydratedProps = $this->dehydrateComponent($this->mountComponent('form_with_live_collection_type'));
        $token = null;

        $this->browser()
            ->get('/_components/form_with_live_collection_type?data='.urlencode(json_encode($dehydratedProps)))
            ->use(function (Crawler $crawler) use (&$dehydratedProps, &$token) {
                // mimic user typing
                $dehydratedProps['blog_post_form']['content'] = 'changed description by user';
                $dehydratedProps['validatedFields'] = ['blog_post_form.content'];
                $token = $crawler->filter('div')->first()->attr('data-live-csrf-value');
            })
            // post to action, which will add a new embedded comment
            ->post('/_components/form_with_live_collection_type/addCollectionItem', [
                'body' => json_encode(['data' => $dehydratedProps, 'args' => ['name' => 'blog_post_form[comments]']]),
                'headers' => ['X-CSRF-TOKEN' => $token],
            ])
            ->assertStatus(422)
            // look for original embedded form
            ->assertContains('<textarea id="blog_post_form_comments_0_content"')
            // look for new embedded form
            ->assertContains('<textarea id="blog_post_form_comments_1_content"')
            // changed text is still present
            ->assertContains('changed description by user</textarea>')
            // check that validation happened and stuck
            ->assertContains('The content field is too short')
            // make sure the title field did not suddenly become validated
            ->assertNotContains('The title field should not be blank')
            ->use(function (Crawler $crawler) use (&$dehydratedProps, &$token) {
                $div = $crawler->filter('[data-controller="live"]');
                $liveProps = json_decode($div->attr('data-live-props-value'), true);
                // make sure the 2nd collection type was initialized, that it didn't
                // just "keep" the empty array that we set it to in the component
                $this->assertEquals(
                    [
                        ['content' => ''],
                        ['content' => ''],
                    ],
                    $liveProps['blog_post_form']['comments']
                );

                // grab the latest live data
                $dehydratedProps = $liveProps;
                // fake that this field was being validated
                $dehydratedProps['validatedFields'][] = 'blog_post_form.0.comments.content';
                $token = $div->attr('data-live-csrf-value');
            })

            // post to action, which will remove the original embedded comment
            ->post('/_components/form_with_live_collection_type/removeCollectionItem', [
                'body' => json_encode(['data' => $dehydratedProps, 'args' => ['name' => 'blog_post_form[comments]', 'index' => '0']]),
                'headers' => ['X-CSRF-TOKEN' => $token],
            ])
            ->assertStatus(422)
            // the original embedded form should be gone
            ->assertNotContains('<textarea id="blog_post_form_comments_0_content"')
            // the added one should still be present
            ->assertContains('<textarea id="blog_post_form_comments_1_content"')
            ->use(function (Crawler $crawler) {
                $div = $crawler->filter('[data-controller="live"]');
                $liveProps = json_decode($div->attr('data-live-props-value'), true);
                // the embedded validated field should be gone, since its data is gone
                $this->assertEquals(
                    ['blog_post_form.content'],
                    $liveProps['validatedFields']
                );
            })
        ;
    }

    public function testDataModelAttributeAutomaticallyAdded(): void
    {
        $dehydrated = $this->dehydrateComponent($this->mountComponent('form_with_collection_type'));

        $this->browser()
            ->get('/_components/form_with_collection_type?data='.urlencode(json_encode($dehydrated)))
            ->assertElementAttributeContains('form', 'data-model', 'on(change)|*')
        ;
    }
}
