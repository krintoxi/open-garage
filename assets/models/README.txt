Optional 3D models for the dashboard hero.

Put a .glb here (front of the vehicle facing +X, wheels on y=0, real-world metres) and set the vehicle's
`model3d` column to its file name, e.g.:

  sqlite3 data/garage.sqlite "UPDATE vehicle SET model3d='my-car.glb' WHERE id=1"

assets/car3d.js is the viewer. Its hotspot positions, X-ray parts and the scale constants (MODEL_LEN / REAL_LEN)
were tuned for a Mercedes W204 C-Class sedan; adjust them for your model. Keep the model's license credit
(the CREDIT constant) when the model's license asks for attribution. Use only models you have the right to
redistribute; no model is shipped with this repository.

Vehicles without a model get the "showroom" view: your photo, a matching stand-in photo, or a drawing.
