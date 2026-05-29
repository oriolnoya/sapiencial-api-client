<?php

namespace sapiencial\sapiencialapiclient\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use sapiencial\sapiencialapiclient\Plugin;
use Throwable;

class ContentModelService extends Component
{
    public function ensureContentModel(): void
    {
        $settings = Plugin::$plugin->getSettings();

        $this->ensureSectionWithEntryType($settings->sapiencialBooksSectionHandle, 'Sapiencial Books', 'Sapiencial Book');
        $this->ensureSectionWithEntryType($settings->sapiencialChaptersSectionHandle, 'Sapiencial Chapters', 'Sapiencial Chapter');
        $this->ensureSectionWithEntryType($settings->sapiencialResourcesSectionHandle, 'Sapiencial Resources', 'Sapiencial Resource');
        $this->ensureSectionWithEntryType($settings->sapiencialPersonsSectionHandle, 'Sapiencial Persons', 'Sapiencial Person');
    }

    private function ensureSectionWithEntryType(string $sectionHandle, string $sectionName, string $entryTypeName): void
    {
        $entriesService = Craft::$app->getEntries();
        $section = $entriesService->getSectionByHandle($sectionHandle);
        if ($section) {
            $this->ensureAtLeastOneEntryType($section, $entryTypeName);
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
    }

    private function ensureAtLeastOneEntryType(Section $section, string $entryTypeName): void
    {
        $entriesService = Craft::$app->getEntries();
        $existing = $entriesService->getEntryTypesBySectionId((int)$section->id);
        if (!empty($existing)) {
            return;
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
    }
}
