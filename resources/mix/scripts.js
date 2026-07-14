const glob = require('glob');

// Keenthemes' plugins
var componentJs = glob.sync(`resources/_keenthemes/src/js/components/*.js`) || [];
var accessibilityJs = 'resources/_keenthemes/src/js/layout/accessibility.js';
var coreLayoutJs = (glob.sync(`resources/_keenthemes/src/js/layout/*.js`) || [])
    .filter(file => file !== accessibilityJs);

module.exports = [
    ...componentJs,
    accessibilityJs,
    ...coreLayoutJs,
    'resources/mix/common/button-ajax.js'
];
