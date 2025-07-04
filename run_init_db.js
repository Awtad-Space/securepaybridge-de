const { exec } = require('child_process');

        console.log('Attempting to run auth/init_db.php using Node.js...');

        // Execute the php script
        const phpProcess = exec('php auth/init_db.php', (error, stdout, stderr) => {
            if (error) {
                console.error(`Error executing PHP script: ${error.message}`);
                console.error(`Stderr: ${stderr}`);
                process.exitCode = 1; // Indicate failure
                return;
            }
            if (stderr) {
                console.warn(`PHP script produced warnings/errors on stderr:\n${stderr}`);
            }
            console.log(`PHP script output (stdout):\n${stdout}`);
            console.log('PHP script finished.');
        });

        phpProcess.on('exit', (code) => {
          console.log(`PHP process exited with code ${code}`);
          // Ensure Node process exits with the same code if not already set by error handler
          if (process.exitCode === undefined) {
            process.exitCode = code;
          }
        });
