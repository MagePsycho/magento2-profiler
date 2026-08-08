<?php
/**
 * This file is part of the MagePsycho_Profiler package.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this package
 * to newer versions in the future.
 *
 * @author   Raj KB <rajkb@magepsycho.com>
 * @license  Open Software License (OSL 3.0)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace MagePsycho\Profiler\Block\Adminhtml\System\Config\Form\Fieldset;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Renderer\RendererInterface;
use MagePsycho\Profiler\Model\Config\ModuleMetadata;

class Support extends Template implements RendererInterface
{
    /**
     * @var string
     */
    protected $_template = 'MagePsycho_Profiler::system/config/form/fieldset/support.phtml';

    /**
     * @var ModuleMetadata
     */
    protected $moduleMetadata;

    public function __construct(
        Context $context,
        ModuleMetadata $moduleMetadata,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->moduleMetadata = $moduleMetadata;
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        if ($this->moduleMetadata->soldViaMagentoMarketplace()) {
            return '';
        }
        return $this->toHtml();
    }

    public function getMageVersion()
    {
        $mageVersion = $this->moduleMetadata->getMageVersion();
        $mageEdition = $this->moduleMetadata->getMageEdition();
        switch ($mageEdition) {
            case 'Community':
                $mageEdition = 'CE';
                break;
            case 'Enterprise':
                $mageEdition = 'EE';
                break;
        }

        return $mageEdition . ' ' . $mageVersion;
    }

    public function getModuleVersion()
    {
        return $this->moduleMetadata->getVersion();
    }

    public function getExtensionName()
    {
        return $this->moduleMetadata->getName();
    }

    public function getPlatform()
    {
        return $this->moduleMetadata->getPlatformShort();
    }
}
