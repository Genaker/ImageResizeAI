function generateDescriptiveFilename(image1, image2 = null, prompt) {
    /**
     * Generate a descriptive filename based on the input image filenames and prompt.
     *
     * @param {string} image1 - Path or URL to first image
     * @param {string|null} image2 - Optional path or URL to second image
     * @param {string} prompt - The generation prompt
     * @returns {string} A filesystem-safe filename
     */

    function extractBaseName(imagePath) {
        /** Extract meaningful base name from image path or URL. */
        let pathPart;
        if (imagePath.startsWith('http://') || imagePath.startsWith('https://')) {
            // For URLs, get the last part of the path
            const urlParts = imagePath.split('/');
            pathPart = urlParts[urlParts.length - 1].split('.')[0]; // Remove extension
        } else {
            // For local paths
            pathPart = path.basename(imagePath, path.extname(imagePath));
        }

        // Clean the name: remove common suffixes like _main_1, _1, etc.
        pathPart = pathPart.replace(/_[a-z]+_\d+$/, '') // Remove _main_1, _front_2, etc.
                          .replace(/_\d+$/, ''); // Remove trailing _1, _2, etc.

        // Keep only alphanumeric and underscores, replace others
        pathPart = pathPart.replace(/[^\w]/g, '_')
                          .replace(/_+/g, '_')
                          .replace(/^_+|_+$/g, '');

        return pathPart.toLowerCase();
    }

    // Extract base names
    const base1 = extractBaseName(image1);
    const base2 = image2 ? extractBaseName(image2) : null;

    // Combine bases
    let combinedBase;
    if (base2 && base1 !== base2) {
        combinedBase = `${base1}_${base2}`;
    } else {
        combinedBase = base1;
    }

    // If combined base is too short or generic, add some prompt words
    if (combinedBase.length < 5 || ['image', 'photo', 'pic'].includes(combinedBase)) {
        // Extract first few meaningful words from prompt
        const words = prompt.toLowerCase().match(/\b\w+\b/g) || [];
        const stopWords = new Set(['a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'create', 'make', 'generate', 'image', 'photo', 'picture']);
        const meaningfulWords = words.filter(word => !stopWords.has(word) && word.length > 2).slice(0, 2);
        if (meaningfulWords.length > 0) {
            combinedBase = `${combinedBase}_${meaningfulWords.join('_')}`;
        }
    }

    // Sanitize for filesystem
    combinedBase = combinedBase.replace(/[^\w\-_]/g, '_')
                               .replace(/_+/g, '_')
                               .replace(/^_+|_+$/g, '');

    // Add timestamp for uniqueness
    const timestamp = Math.floor(Date.now() / 1000);

    // Create final filename (limit total length)
    let filename = `${combinedBase}_${timestamp}`;
    if (filename.length > 100) {  // Reasonable filename length limit
        filename = filename.substring(0, 95) + `_${timestamp}`;
    }

    return filename;
}

module.exports = { generateDescriptiveFilename };