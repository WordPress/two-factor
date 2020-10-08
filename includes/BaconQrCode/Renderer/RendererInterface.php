<?php
/**
 * BaconQrCode
 *
 * @link      http://github.com/Bacon/BaconQrCode For the canonical source repository
 * @copyright 2013 Ben 'DASPRiD' Scholzen
 * @license   http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */

namespace TwoFactor\BaconQrCode\Renderer;

use TwoFactor\BaconQrCode\Encoder\QrCode;

/**
 * Renderer interface.
 */
interface RendererInterface
{
    /**
     * Renders a QR code.
     *
     * @param  QrCode $qrCode
     * @return string
     */
    public function render(QrCode $qrCode);
}
