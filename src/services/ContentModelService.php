<?php

namespace sapiencial\sapiencialapiclient\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry as EntryElement;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Date;
use craft\fields\PlainText;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use sapiencial\sapiencialapiclient\Plugin;
use yii\base\Exception;

class ContentModelService extends Component
{
    public const PAYLOAD_JSON_FIELD_HANDLE = 'sapiencialPayloadJson';
    public const REFRESHED_AT_FIELD_HANDLE = 'sapiencialRefreshedAt';

    public function ensureContentModel(): void
    {
        [$payloadField, $refreshedAtField] = $this->ensureSyncFields();

        $settings = Plugin::$plugin->getSettings();

        $this->ensureSectionWithEntryType($settings->sapiencialBooksSectionHandle, 'Sapiencial > Book', 'Sapiencial > Book', $payloadField, $refreshedAtField);
        $this->ensureSectionWithEntryType($settings->sapiencialChaptersSectionHandle, 'Sapiencial > Chapter', 'Sapiencial > Chapter', $payloadField, $refreshedAtField);
        $this->ensureSectionWithEntryType($settings->sapiencialResourcesSectionHandle, 'Sapiencial > Resource', 'Sapiencial > Resource', $payloadField, $refreshedAtField);
        $this->ensureSectionWithEntryType($settings->sapiencialPersonsSectionHandle, 'Sapiencial > Person', 'Sapiencial > Person', $payloadField, $refreshedAtField);
        $this->ensureSectionWithEntryType($settings->sapiencialTopicsSectionHandle, 'Sapiencial > Topic', 'Sapiencial > Topic', $payloadField, $refreshedAtField);
    }

    private function ensureSectionWithEntryType(string $sectionHandle, string $sectionName, string $entryTypeName, PlainText $payloadField, Date $refreshedAtField): void
    {
        $entriesService = Craft::$app->getEntries();
        $section = $entriesService->getSectionByHandle($sectionHandle);
        if ($section) {
            if ($section->name !== $sectionName) {
                $section->name = $sectionName;
                $entriesService->saveSection($section, false);
            }
            $entryType = $this->ensureAtLeastOneEntryType($section, $entryTypeName);
            $this->ensureEntryTypeHasSyncFields($entryType, $payloadField, $refreshedAtField);
            return;
        }

        $entryType = $entriesService->getEntryTypeByHandle(StringHelper::toHandle($entryTypeName));
        if (!$entryType) {
            $entryType = new EntryType();
            $entryType->name = $entryTypeName;
            $entryType->handle = StringHelper::toHandle($entryTypeName);
            $entryType->titleFormat = '{title}';
            $entryType->hasTitleField = true;
            $entryType->showStatusField = true;
            $entriesService->saveEntryType($entryType);
        }

        $section = new Section([
            'name' => $sectionName,
            'handle' => $sectionHandle,
            'type' => Section::TYPE_CHANNEL,
            'enableVersioning' => true,
            'previewTargets' => [],
        ]);

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $siteSettings[] = new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => false,
                'uriFormat' => null,
                'template' => null,
            ]);
        }
        $section->setSiteSettings($siteSettings);
        $section->setEntryTypes([$entryType]);

        $entriesService->saveSection($section);

        // Reload and ensure field layout has sync fields.
        $reloadedSection = $entriesService->getSectionByHandle($sectionHandle);
        if ($reloadedSection) {
            foreach ($entriesService->getEntryTypesBySectionId((int)$reloadedSection->id) as $reloadedEntryType) {
                $this->ensureEntryTypeHasSyncFields($reloadedEntryType, $payloadField, $refreshedAtField);
            }
        }
    }

    private function ensureAtLeastOneEntryType(Section $section, string $entryTypeName): EntryType
    {
        $entriesService = Craft::$app->getEntries();
        $existing = $entriesService->getEntryTypesBySectionId((int)$section->id);
        if (!empty($existing)) {
            if ($existing[0]->name !== $entryTypeName) {
                $existing[0]->name = $entryTypeName;
                $entriesService->saveEntryType($existing[0]);
            }
            return $existing[0];
        }

        $entryType = new EntryType();
        $entryType->name = $entryTypeName;
        $entryType->handle = StringHelper::toHandle($entryTypeName . '-' . $section->handle);
        $entryType->titleFormat = '{title}';
        $entryType->hasTitleField = true;
        $entryType->showStatusField = true;
        $entriesService->saveEntryType($entryType);

        $section->setEntryTypes([$entryType]);
        $entriesService->saveSection($section, false);
        return $entryType;
    }

    private function ensureSyncFields(): array
    {
        $fieldsService = Craft::$app->getFields();

        $payloadField = $fieldsService->getFieldByHandle(self::PAYLOAD_JSON_FIELD_HANDLE);
        if (!$payloadField instanceof PlainText) {
            $payloadField = new PlainText();
            $payloadField->name = 'Sapiencial Payload JSON';
            $payloadField->handle = self::PAYLOAD_JSON_FIELD_HANDLE;
            $payloadField->multiline = true;
            $payloadField->initialRows = 8;
            $payloadField->code = true;
            $fieldsService->saveField($payloadField);
        }

        $refreshedAtField = $fieldsService->getFieldByHandle(self::REFRESHED_AT_FIELD_HANDLE);
        if (!$refreshedAtField instanceof Date) {
            $refreshedAtField = new Date();
            $refreshedAtField->name = 'Sapiencial Refreshed At';
            $refreshedAtField->handle = self::REFRESHED_AT_FIELD_HANDLE;
            $refreshedAtField->showDate = true;
            $refreshedAtField->showTime = true;
            $fieldsService->saveField($refreshedAtField);
        }

        return [$payloadField, $refreshedAtField];
    }

    private function ensureEntryTypeHasSyncFields(EntryType $entryType, PlainText $payloadField, Date $refreshedAtField): void
    {
        $entriesService = Craft::$app->getEntries();
        $layout = $entryType->getFieldLayout();
        if ($layout === null) {
            $layout = new FieldLayout();
            $layout->type = EntryElement::class;
        }
        $layout->type = EntryElement::class;

        $tabs = $layout->getTabs();
        if (empty($tabs)) {
            $tabs = [new FieldLayoutTab(['name' => 'Content'])];
        }

        $tab = $tabs[0];
        foreach ($tabs as $t) {
            $t->setLayout($layout);
        }
        $elements = $tab->getElements();
        $existingFieldUids = [];
        foreach ($elements as $element) {
            if ($element instanceof CustomField) {
                $existingFieldUids[] = $element->getFieldUid();
            }
        }

        if (!in_array($payloadField->uid, $existingFieldUids, true)) {
            $elements[] = new CustomField($payloadField);
        }
        if (!in_array($refreshedAtField->uid, $existingFieldUids, true)) {
            $elements[] = new CustomField($refreshedAtField);
        }
        if (empty($elements)) {
            // Keep a valid entry layout baseline for Craft entry types.
            $elements[] = new EntryTitleField();
        }

        $tab->setElements($elements);
        $layout->setTabs($tabs);
        $entryType->setFieldLayout($layout);
        if (!$entriesService->saveEntryType($entryType, true)) {
            $errors = implode(' | ', array_map(static fn(array $e): string => implode(', ', $e), $entryType->getErrors()));
            throw new Exception(sprintf('Unable to attach sync fields to entry type %s: %s', $entryType->handle, $errors));
        }
    }
}
